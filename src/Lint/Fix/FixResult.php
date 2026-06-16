<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

/**
 * Outcome of a {@see FixApplicator::apply()} run.
 *
 * `$skipped` fixes were dropped due to overlap with an already-applied edit in the same file.
 */
final class FixResult
{
    public bool $hasChanges {
        get => $this->applied !== [];
    }

    /**
     * @param list<Fix>    $applied
     * @param list<Fix>    $skipped
     * @param list<string> $modifiedFiles
     */
    public function __construct(
        public readonly array $applied,
        public readonly array $skipped,
        public readonly array $modifiedFiles,
    ) {}
}
