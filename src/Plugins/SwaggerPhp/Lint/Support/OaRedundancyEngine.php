<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use Closure;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Lint\Finding;
use ReflectionClass;
use ReflectionMethod;

/**
 * The shared skeleton of the migration redundancy rules, factored out so the schema-level and
 * operation-level rules ({@see \Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantWithInference}
 * and {@see \Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantOperationWithInference}) become
 * thin adapters that supply only what differs: the authored annotation and inference's counterpart,
 * the comparator, the reflector locating the source bytes, an optional extra keep-guard, and the
 * finding to emit.
 *
 * One engine over one comparison ({@see OaRedundancyComparator}) is what lets #122 part 2
 * (`oa-replaceable-by-attribute`) be a *configuration* — a non-empty candidate passed to `evaluate()`
 * — rather than a third parallel rule: redundancy is `candidate = ∅`, replaceability is
 * `candidate = {attribute set}`, both over the same inference-only control and the same `subsumes()`.
 *
 * @internal
 */
final readonly class OaRedundancyEngine
{
    /**
     * Decide whether the authored annotation is redundant (or, with a non-empty `$candidate`,
     * replaceable) and, if so, build the removal finding. Returns null when the annotation is
     * load-bearing — inference produced no counterpart, does not reproduce it, an extra guard keeps
     * it, or its shape cannot be located for the fixer.
     *
     * @param Closure(): (ReflectionClass<object>|ReflectionMethod) $reflector     locates the source
     *                                                                             bytes for shape detection;
     *                                                                             a thunk so reflection is
     *                                                                             deferred until subsumption
     *                                                                             holds (not built per node)
     * @param (callable(AuthoredAnnotationShape): Finding)          $buildFinding  builds the finding from the
     *                                                                             detected annotation shape
     * @param null|(callable(): bool)                               $isLoadBearing extra keep-guard evaluated
     *                                                                             only once subsumption holds
     *                                                                             (e.g. the schema rule's
     *                                                                             dangling-`$ref` check); null
     *                                                                             when the rule has no guard
     * @param list<OA\AbstractAnnotation>                           $candidate     replacement annotations to
     *                                                                             fold onto inference; ∅ =
     *                                                                             pure redundancy
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
        // Inference produces no counterpart for this construct: the annotation is load-bearing.
        if ($inferred === null) {
            return null;
        }

        // Fire only when inference (⊕ candidate) reproduces everything the author wrote.
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
