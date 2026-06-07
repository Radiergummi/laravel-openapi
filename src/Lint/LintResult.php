<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

/**
 * Output of a single {@see LintRunner::run()} invocation.
 *
 * Carries the filtered, suppressed, threshold-applied finding list plus the resolved level the
 * findings were filtered against, the process exit code, and (when computed) the
 * {@see CoverageSummary}. `exitCode` mirrors the convention used by the artisan command
 * (`0` clean, `1` findings present) so direct CLI integrations can pass it through without
 * re-deriving it.
 */
final readonly class LintResult
{
    /**
     * @param list<Finding>    $findings Findings remaining after merging suppressions, --only/--skip,
     *                                   config disabled rules, severity overrides, and the level
     *                                   threshold.
     * @param int              $level    The integer level used for the threshold filter (after
     *                                   resolution of the 'max' sentinel and any --only widening).
     * @param int              $exitCode Process exit code. With no coverage gate: 0 when $findings is
     *                                   empty, 1 otherwise. With a gate active: 0 unless coverage is
     *                                   below the floor or the finding count exceeds the budget.
     * @param ?CoverageSummary $coverage The documentation-coverage summary, or null when not computed
     *                                   (e.g. the --fix path).
     */
    public function __construct(
        public array $findings,
        public int $level,
        public int $exitCode,
        public ?CoverageSummary $coverage = null,
    ) {}
}
