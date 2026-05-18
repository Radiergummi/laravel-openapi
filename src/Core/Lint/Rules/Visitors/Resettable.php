<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules\Visitors;

/**
 * Implemented by rules that accumulate state across node visits.
 *
 * The tree walker calls {@see reset()} before each walk to ensure clean state,
 * preventing stale data from persisting across multiple runs in long-lived
 * processes (e.g. Laravel Octane or test suites).
 */
interface Resettable
{
    /** Clear any accumulated state from previous walks. */
    public function reset(): void;
}
