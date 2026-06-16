<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

/**
 * A single source edit proposed by a {@see Fixer}: target file, human description, originating
 * rule, and the mechanical change. A fixer may emit several `Fix`es per finding; {@see FixApplicator}
 * groups them by file and applies them.
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
