<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Radiergummi\OpenApi\Lint\Finding;

/**
 * Outcome of one {@see FixRunner::run()}: the underlying {@see FixResult}, the findings left
 * unresolved, and the lint level the run was scoped to (for rendering). The fixes that *were*
 * applied are {@see FixResult::$applied}.
 *
 * `$dryRun` records whether this was a `--check` pass so {@see exitCode()} can apply the right
 * convention: `--check` fails when any fix *would* apply, while `--fix` fails when any finding
 * remains unresolved.
 */
final readonly class FixRunResult
{
    /**
     * @param list<Finding> $remainingFindings
     */
    public function __construct(
        public FixResult $fixResult,
        public array $remainingFindings,
        public int $level,
        public bool $dryRun,
    ) {}

    /**
     * Exit code per the `openapi:lint` contract: `0` clean, `1` work remains/pending.
     */
    public function exitCode(): int
    {
        if ($this->dryRun) {
            return $this->fixResult->hasChanges ? 1 : 0;
        }

        return $this->remainingFindings === [] ? 0 : 1;
    }
}
