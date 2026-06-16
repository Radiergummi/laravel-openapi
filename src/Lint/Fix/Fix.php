<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Radiergummi\OpenApi\Lint\Fix\Ast\AstOperation;

/**
 * A single source edit proposed by a {@see Fixer}: target file, human description, originating
 * rule, and the mechanical change. A fixer may emit several `Fix`es per finding; {@see FixApplicator}
 * groups them by file and applies them.
 *
 * The mechanical change is either an {@see AstOperation} (node mutation, reprinted with the
 * format-preserving printer) or a byte-addressed {@see FixOperation} ({@see FixOperation::toEdit()}).
 */
final readonly class Fix
{
    public function __construct(
        public string $file,
        public string $description,
        public string $ruleId,
        public AstOperation|FixOperation $operation,
    ) {}
}
