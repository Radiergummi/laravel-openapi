<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

/**
 * Outcome of a {@see FixApplicator::apply()} run.
 *
 * Each entry in `$skipped` carries the typed reason it was not applied: a same-node conflict with a
 * kept fix, a target node that could not be located, or a format-preserving print failure.
 */
final class FixResult
{
    public bool $hasChanges {
        get => $this->applied !== [];
    }

    /**
     * @param list<Fix>        $applied
     * @param list<SkippedFix> $skipped
     * @param list<string>     $modifiedFiles
     * @param list<FileChange> $changes       Original→new contents per modified file, captured for
     *                                        `--show-diff`. Parallel to `$modifiedFiles`; outside
     *                                        the frozen JSON envelope.
     *
     * @internal `$changes` is a Fix-backend internal, not part of any stable contract.
     */
    public function __construct(
        public readonly array $applied,
        public readonly array $skipped,
        public readonly array $modifiedFiles,
        public readonly array $changes = [],
    ) {}
}
