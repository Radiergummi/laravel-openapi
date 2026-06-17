<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Radiergummi\OpenApi\Lint\Finding;

use function array_map;
use function count;

/**
 * Outcome of one {@see FixRunner::run()}: applied fixes, unresolved findings, and scope metadata.
 *
 * `$dryRun` (--check mode) causes {@see exitCode()} to fail when any fix *would* apply, whereas
 * a real run fails only when findings remain unresolved.
 */
final readonly class FixRunResult
{
    /** The fix-run JSON envelope schema version, bumped only on a breaking shape change. */
    public const string SCHEMA_VERSION = '1';

    /**
     * @param list<Finding> $remainingFindings
     * @param int           $withheldDestructiveCount Fixes gated behind `--fix=dangerous` that were
     *                                                not applied this run (their findings stay in
     *                                                `$remainingFindings`).
     */
    public function __construct(
        public FixResult $fixResult,
        public array $remainingFindings,
        public int $level,
        public bool $dryRun,
        public int $withheldDestructiveCount = 0,
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

    /**
     * The frozen fix-run JSON envelope. Distinct from the lint payload: it uses a top-level
     * `remaining` key (not `findings`), so existing lint-JSON consumers are unaffected. The
     * `remaining` entries reuse {@see Finding}'s `JsonSerializable` shape verbatim. This is the
     * stable CI-facing contract for `--fix`/`--check`.
     *
     * @return array{
     *     schema_version: string,
     *     mode: 'check'|'fix',
     *     applied: int,
     *     skipped: list<array{rule_id: string, file: string, reason: string}>,
     *     withheld_destructive: int,
     *     modified_files: list<string>,
     *     remaining: list<Finding>,
     *     exit_code: int
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => $this->dryRun ? 'check' : 'fix',
            'applied' => count($this->fixResult->applied),
            'skipped' => array_map(
                static fn(SkippedFix $skipped): array => [
                    'rule_id' => $skipped->fix->ruleId,
                    'file' => $skipped->fix->file,
                    'reason' => $skipped->reason->value,
                ],
                $this->fixResult->skipped,
            ),
            'withheld_destructive' => $this->withheldDestructiveCount,
            'modified_files' => $this->fixResult->modifiedFiles,
            'remaining' => $this->remainingFindings,
            'exit_code' => $this->exitCode(),
        ];
    }
}
