<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use OpenApi\Annotations as OA;
use OpenApi\Generator;

use function is_array;
use function Radiergummi\OpenApi\is_defined;

/**
 * Decides whether inference reproduces a hand-authored reusable `OA\Response`/`OA\Parameter`
 * component, so the component definition can be removed.
 *
 * The harvested operation that uses a component keeps its response/parameter as a bare `$ref`, so
 * the authored body lives only in the component pool, not inline on the operation. The comparator
 * is therefore handed the resolved component body directly and asks whether the inference-only
 * operation for the referencing route already produces an equivalent inline response/parameter via
 * {@see SchemaEquivalence::subsumes()}. The component-identity field (`response` / `parameter`) is
 * not a descriptor, so it is aligned/dropped before comparing, mirroring how the schema name is
 * ignored on {@see OA\Schema}.
 *
 * @internal
 */
final readonly class ComponentSubsumption
{
    public function __construct(private SchemaEquivalence $equivalence) {}

    /**
     * Whether the inferred operation produces a response for the component's status code that
     * subsumes the authored response component body.
     */
    public function responseSubsumed(OA\Operation $inferred, OA\Response $component, string $status): bool
    {
        $candidate = clone $component;

        // Match the inferred per-status response's identity so only the descriptor fields compare.
        $candidate->response = $status;

        foreach (is_array($inferred->responses) ? $inferred->responses : [] as $inferredResponse) {
            if ((string) $inferredResponse->response === $status
                && $this->equivalence->subsumes($inferredResponse, $candidate)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the inferred operation produces a parameter that subsumes the authored parameter
     * component body.
     */
    public function parameterSubsumed(OA\Operation $inferred, OA\Parameter $component): bool
    {
        $candidate = clone $component;

        // The component name is identity, not a descriptor; drop it so only (name, in, schema, …) compare.
        $candidate->parameter = Generator::UNDEFINED;

        foreach (is_array($inferred->parameters) ? $inferred->parameters : [] as $inferredParameter) {
            if ($inferredParameter instanceof OA\Parameter
                && $this->equivalence->subsumes($inferredParameter, $candidate)
            ) {
                return true;
            }
        }

        return false;
    }

    /** The status code a referencing operation uses for the response component, or null. */
    public function referencingStatus(OA\Operation $operation, string $pointer): ?string
    {
        foreach (is_array($operation->responses) ? $operation->responses : [] as $response) {
            if (is_defined($response->ref) && $response->ref === $pointer && is_defined($response->response)) {
                return (string) $response->response;
            }
        }

        return null;
    }
}
