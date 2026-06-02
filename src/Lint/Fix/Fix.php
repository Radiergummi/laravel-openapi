<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

/**
 * A single, self-contained source edit a {@see Fixer} proposes for one finding: which file to
 * touch (`$file`), why (`$description`, surfaced in `--fix` output), which rule it
 * resolves (`$ruleId`), and the mechanical change itself (`$operation`).
 *
 * A fixer may emit several `Fix`es for one finding (e.g. removing two duplicate attributes); the
 * {@see FixApplicator} collects them across all findings, groups by file, and applies them.
 */
final readonly class Fix
{
    public function __construct(
        public string $file,
        public string $description,
        public string $ruleId,
        public FixOperation $operation,
    ) {}
}
