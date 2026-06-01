<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function array_count_values;
use function sprintf;

final class TagDuplicate implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->tags === []) {
            return;
        }

        $counts = array_count_values($operation->tags);

        foreach ($counts as $tag => $count) {
            if ($count <= 1) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Tag "%s" is applied %d times on %s %s',
                    $tag,
                    $count,
                    $operation->method,
                    $operation->pathUri,
                ),
                fixHint: 'Remove the duplicate #[Tag] attribute.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'tag.duplicate';
    }

    #[Override]
    public function level(): int
    {
        // Duplicate tags on an operation violate the OpenAPI 3.1 spec
        // (tags MUST be unique) — a correctness error, not a convention nit.
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return 'Two top-level tag definitions share the same name.';
    }
}
