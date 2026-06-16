<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use LogicException;
use OpenApi\Annotations as OA;
use Override;

use function in_array;
use function is_array;
use function Radiergummi\OpenApi\is_defined;

/**
 * The comparator behind {@see \Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantOperationWithInference}.
 *
 * Scalar metadata must match exactly; collections and schema-bearing members are checked via
 * {@see SchemaEquivalence}. A `$ref` response component that the harvester never merges is
 * treated as not reproducible, so the operation is kept.
 *
 * Only the empty-candidate case is implemented; throws on a non-empty candidate.
 *
 * @internal
 */
final readonly class OperationSubsumption implements OaRedundancyComparator
{
    public function __construct(private SchemaEquivalence $equivalence) {}

    /**
     * @param list<OA\AbstractAnnotation> $candidate
     *
     * @throws LogicException when a non-empty candidate is passed (operation-level fold not implemented)
     */
    #[Override]
    public function subsumes(
        OA\AbstractAnnotation $inferred,
        OA\AbstractAnnotation $authored,
        array $candidate = [],
    ): bool {
        if ($candidate !== []) {
            throw new LogicException(
                'Operation-level candidate-replacement is not implemented; fold the candidate '
                . 'onto the inferred operation before comparing.',
            );
        }

        if (!$inferred instanceof OA\Operation || !$authored instanceof OA\Operation) {
            return false;
        }

        return $this->subsumesAuthoredOperation($inferred, $authored);
    }

    /**
     * Whether inference reproduces every authored field. Routing identity is not compared; both
     * sides describe the same route by construction.
     */
    private function subsumesAuthoredOperation(OA\Operation $inferred, OA\Operation $authored): bool
    {
        foreach (['summary', 'description', 'operationId'] as $field) {
            if (!is_defined($authored->{$field})) {
                continue;
            }

            if (!is_defined($inferred->{$field}) || $authored->{$field} !== $inferred->{$field}) {
                return false;
            }
        }

        if (is_defined($authored->tags) && is_array($authored->tags)) {
            $inferredTags = is_array($inferred->tags) ? $inferred->tags : [];

            foreach ($authored->tags as $tag) {
                if (!in_array($tag, $inferredTags, true)) {
                    return false;
                }
            }
        }

        if (is_defined($authored->parameters) && is_array($authored->parameters)) {
            $inferredParameters = is_array($inferred->parameters) ? $inferred->parameters : [];

            foreach ($authored->parameters as $parameter) {
                if (!$this->anySubsumes($inferredParameters, $parameter)) {
                    return false;
                }
            }
        }

        if (is_defined($authored->requestBody)) {
            if (!is_defined($inferred->requestBody)
                || !$this->equivalence->subsumes($inferred->requestBody, $authored->requestBody)
            ) {
                return false;
            }
        }

        if (is_defined($authored->responses) && is_array($authored->responses)) {
            $inferredResponses = is_array($inferred->responses) ? $inferred->responses : [];

            foreach ($authored->responses as $response) {
                if (!is_defined($response->response)) {
                    continue;
                }

                // A $ref to a response component the harvester never merges cannot be inferred; keep it.
                if (is_defined($response->ref) || !$this->anySubsumesResponse($inferredResponses, $response)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<array-key, OA\AbstractAnnotation> $inferred
     */
    private function anySubsumes(array $inferred, OA\AbstractAnnotation $authored): bool
    {
        foreach ($inferred as $candidate) {
            if ($this->equivalence->subsumes($candidate, $authored)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, OA\Response> $inferred
     */
    private function anySubsumesResponse(array $inferred, OA\Response $authored): bool
    {
        foreach ($inferred as $candidate) {
            if ((string) $candidate->response === (string) $authored->response
                && $this->equivalence->subsumes($candidate, $authored)
            ) {
                return true;
            }
        }

        return false;
    }
}
