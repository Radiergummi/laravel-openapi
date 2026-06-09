<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use OpenApi\Annotations as OA;

/**
 * Decides whether inference reproduces everything a hand-authored swagger-php annotation says — the
 * core verdict the migration rules share, factored out of the rules so the schema-level and
 * operation-level comparisons differ only in their implementation, not in the rule skeleton around
 * them ({@see OaRedundancyEngine}).
 *
 * The `$candidate` parameter is the **candidate-replacement seam** #122 part 2 targets: an empty
 * candidate asks pure redundancy (does inference *alone* reproduce the annotation?), while a
 * non-empty candidate asks replaceability (does inference reproduce it once augmented with the
 * annotations one of our authoring attributes would contribute — `inference ⊕ candidate`?). Both
 * questions are the same mechanism over the same inference-only control; only the candidate differs.
 *
 * @internal
 */
interface OaRedundancyComparator
{
    /**
     * Whether `$inferred`, optionally augmented with `$candidate`, subsumes `$authored` — reproduces
     * everything the author wrote, and possibly more.
     *
     * @param list<OA\AbstractAnnotation> $candidate replacement annotations to fold onto the inferred
     *                                               side before comparing; empty = pure redundancy
     */
    public function subsumes(OA\AbstractAnnotation $inferred, OA\AbstractAnnotation $authored, array $candidate = []): bool;
}
