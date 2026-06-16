<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Visitors;

/**
 * Implemented by rules that accumulate state across node visits.
 * The tree walker calls {@see reset()} before each walk to ensure clean state.
 */
interface Resettable
{
    /** Clear any accumulated state from previous walks. */
    public function reset(): void;
}
