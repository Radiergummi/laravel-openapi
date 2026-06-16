<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Radiergummi\OpenApi\Lint\Finding;

/**
 * Outcome of one {@see FixRunner::run()}: applied fixes, unresolved findings, and scope metadata.
 *
 * `$dryRun` (--check mode) causes {@see exitCode()} to fail when any fix *would* apply, whereas
 * a real run fails only when findings remain unresolved.
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
     * Returns the exit code: `0` clean, `1` work remains.
     */
    public function exitCode(): int
    {
        if ($this->dryRun) {
            return $this->fixResult->hasChanges ? 1 : 0;
        }

        return $this->remainingFindings === [] ? 0 : 1;
    }
}
