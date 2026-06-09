<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use OpenApi\Annotations as OA;

use function in_array;
use function is_array;
use function Radiergummi\OpenApi\is_defined;

/**
 * Operation-level redundancy comparison: a field-by-field walk of the authored operation against
 * inference's operation for the same route. The comparator behind
 * {@see \Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantOperationWithInference}.
 *
 * Scalar metadata (`summary` / `description` / `operationId`) must match exactly; collections
 * (`tags`) and schema-bearing members (`responses` / `parameters` / `requestBody`) must be subsumed
 * by inference's via {@see SchemaEquivalence}. `parameters` / `requestBody` are checked as a
 * conservative guard even though the harvester merges only metadata and `responses`, so an annotation
 * documenting something inference cannot reproduce is kept rather than silently dropped. An
 * `@OA\Response(ref=…)` pointing at a response component the harvester never merges is treated as not
 * reproducible, so the operation is kept.
 *
 * Operation-level *replaceability* (a non-empty candidate) is future work; the candidate-replacement
 * seam is proven at schema level by {@see SchemaSubsumption}. This comparator honours only the empty
 * candidate (pure redundancy) and reproduces the operation rule's prior behaviour exactly.
 *
 * @internal
 */
final readonly class OperationSubsumption implements OaRedundancyComparator
{
    public function __construct(private SchemaEquivalence $equivalence) {}

    /**
     * @param list<OA\AbstractAnnotation> $candidate
     */
    public function subsumes(OA\AbstractAnnotation $inferred, OA\AbstractAnnotation $authored, array $candidate = []): bool
    {
        if (!$inferred instanceof OA\Operation || !$authored instanceof OA\Operation) {
            return false;
        }

        return $this->subsumesAuthoredOperation($inferred, $authored);
    }

    /**
     * Whether the inferred operation reproduces every field the harvester merges from the authored
     * one. Routing identity (`path` / `method`) is not compared — both sides describe the same route
     * by construction.
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

                // A response that is itself a `$ref` to a response component the harvester never
                // merges cannot be reproduced by inference — keep the annotation.
                if (is_defined($response->ref) || !$this->anySubsumesResponse($inferredResponses, $response)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether some element of `$inferred` subsumes `$authored`.
     *
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
     * Whether the inferred response for the authored response's status subsumes it.
     *
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
