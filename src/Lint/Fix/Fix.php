<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Radiergummi\OpenApi\Lint\Fix\Ast\AstOperation;

/**
 * A single source edit proposed by a {@see Fixer}: target file, human description, originating
 * rule, and the mechanical change as an {@see AstOperation} (a node mutation reprinted with the
 * format-preserving printer). A fixer may emit several `Fix`es per finding; {@see FixApplicator}
 * groups them by file and applies them.
 *
 * `$safety` defaults to {@see FixSafety::Safe}: every fixer constructs `Fix` positionally without
 * it, so all current behaviour is unchanged. A fixer marks a `Fix` {@see FixSafety::Destructive}
 * when it rewrites hand-curated files or writes far from the finding; such fixes are withheld from
 * a plain `--fix`.
 */
final readonly class Fix
{
    public function __construct(
        public string $file,
        public string $description,
        public string $ruleId,
        public AstOperation $operation,
        public FixSafety $safety = FixSafety::Safe,
    ) {}
}
