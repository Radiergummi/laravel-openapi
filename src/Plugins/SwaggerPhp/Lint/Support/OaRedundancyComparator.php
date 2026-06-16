<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use OpenApi\Annotations as OA;

/**
 * Decides whether inference (optionally augmented by candidate authoring attributes) subsumes a
 * hand-authored swagger-php annotation. Shared by migration rules; schema-level vs operation-level
 * comparisons differ only in their concrete implementation ({@see OaRedundancyEngine}).
 *
 * An empty `$candidate` is a pure redundancy check; a non-empty one asks replaceability.
 *
 * @internal
 */
interface OaRedundancyComparator
{
    /**
     * @param list<OA\AbstractAnnotation> $candidate annotations to fold onto the inferred side;
     *                                               empty = pure redundancy check
     */
    public function subsumes(
        OA\AbstractAnnotation $inferred,
        OA\AbstractAnnotation $authored,
        array $candidate = [],
    ): bool;
}
