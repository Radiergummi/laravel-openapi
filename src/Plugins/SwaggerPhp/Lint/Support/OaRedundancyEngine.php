<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use Closure;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Lint\Finding;
use ReflectionClass;
use ReflectionMethod;

/**
 * Shared skeleton of the migration redundancy rules. The schema-level and operation-level rules
 * are thin adapters over this; a non-empty `$candidate` activates replaceability mode instead of
 * pure redundancy mode, reusing the same {@see OaRedundancyComparator::subsumes()} check.
 *
 * @internal
 */
final readonly class OaRedundancyEngine
{
    /**
     * Returns a finding when the authored annotation is redundant (or replaceable), null when it
     * is load-bearing (no inference counterpart, not subsumed, extra guard fires, or shape
     * undetectable).
     *
     * @param Closure(): (ReflectionClass<object>|ReflectionMethod) $reflector     Thunk; deferred until subsumption holds.
     * @param (callable(AuthoredAnnotationShape): Finding)          $buildFinding  Builds the finding from the detected shape.
     * @param null|(callable(): bool)                               $isLoadBearing Extra keep-guard (e.g., dangling-$ref check).
     * @param list<OA\AbstractAnnotation>                           $candidate     Replacement annotations; empty = pure redundancy.
     */
    public function evaluate(
        OA\AbstractAnnotation $authored,
        ?OA\AbstractAnnotation $inferred,
        OaRedundancyComparator $comparator,
        Closure $reflector,
        callable $buildFinding,
        ?callable $isLoadBearing = null,
        array $candidate = [],
    ): ?Finding {
        // No inference counterpart means the annotation is load-bearing.
        if ($inferred === null) {
            return null;
        }

        if (!$comparator->subsumes($inferred, $authored, $candidate)) {
            return null;
        }

        if ($isLoadBearing !== null && $isLoadBearing()) {
            return null;
        }

        $shape = AuthoredAnnotationShape::detect($reflector());

        if ($shape === null) {
            return null;
        }

        return $buildFinding($shape);
    }
}
