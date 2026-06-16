<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

/**
 * Output of a single {@see LintRunner::run()} invocation: filtered findings, resolved level,
 * process exit code, and optional coverage summary.
 */
final readonly class LintResult
{
    /**
     * @param list<Finding>    $findings Findings after suppressions, rule filters, and level threshold.
     * @param int              $level    Resolved threshold level (after 'max' sentinel and --only widening).
     * @param int              $exitCode 0 = clean, 1 = findings present (or coverage gate failed).
     * @param ?CoverageSummary $coverage Null when the caller discards coverage (e.g. --fix/--check path).
     */
    public function __construct(
        public array $findings,
        public int $level,
        public int $exitCode,
        public ?CoverageSummary $coverage = null,
    ) {}
}
