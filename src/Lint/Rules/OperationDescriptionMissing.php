<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function sprintf;

/**
 * Reports operations that have a summary but no accompanying description.
 *
 * Operations missing both summary and description are covered by `summary.missing` instead.
 */
final class OperationDescriptionMissing implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->summary === null) {
            return;
        }

        if ($operation->description === null) {
            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Operation %s %s has no description',
                    $operation->method->forDisplay(),
                    $operation->pathUri,
                ),
                fixHint: 'Add a description to complement the summary and provide more detail to API consumers.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'operation.description-missing';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'Operation has no description (beyond the summary).';
    }
}
