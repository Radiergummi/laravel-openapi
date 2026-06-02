<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

/**
 * Outcome of a {@see FixApplicator::apply()} run.
 *
 * `$applied` are the fixes that were written (or, in dry-run, that would be written); `$skipped`
 * are fixes dropped because they overlapped an already-applied edit in the same file; and
 * `$modifiedFiles` lists the distinct paths touched.
 */
final readonly class FixResult
{
    /**
     * @param list<Fix>    $applied
     * @param list<Fix>    $skipped
     * @param list<string> $modifiedFiles
     */
    public function __construct(
        public array $applied,
        public array $skipped,
        public array $modifiedFiles,
    ) {}

    /**
     * Whether any fix was (or would be) applied.
     */
    public function hasChanges(): bool
    {
        return $this->applied !== [];
    }
}
