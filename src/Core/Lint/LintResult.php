<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint;

/**
 * Output of a single {@see LintRunner::run()} invocation.
 *
 * Carries the filtered, suppressed, threshold-applied finding list plus the resolved level the
 * findings were filtered against (callers that render output need the level for context — e.g.
 * the formatter prints "linted at level $level"). `exitCode` mirrors the convention used by
 * the artisan command (`0` clean, `1` findings present) so direct CLI integrations can pass it
 * through without re-deriving it.
 */
final readonly class LintResult
{
    /**
     * @param list<Finding> $findings Findings remaining after merging suppressions, --only/--skip,
     *                                config disabled rules, severity overrides, and the level
     *                                threshold.
     * @param int           $level    The integer level used for the threshold filter (after
     *                                resolution of the 'max' sentinel and any --only widening).
     * @param int           $exitCode 0 when $findings is empty, 1 otherwise.
     */
    public function __construct(
        public array $findings,
        public int $level,
        public int $exitCode,
    ) {}
}
