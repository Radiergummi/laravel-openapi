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
use function strcasecmp;
use function trim;

/**
 * Reports operations whose summary and description are identical after trimming and case-folding,
 * making the description redundant.
 *
 * A description should add context beyond what the summary already says. When both fields carry
 * the same text, API consumers gain nothing extra and documentation tooling renders duplicated
 * content.
 */
final class OperationSummaryEqualsDescription implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->summary === null || $operation->description === null) {
            return;
        }

        if (strcasecmp(trim($operation->summary), trim($operation->description)) === 0) {
            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Operation %s %s has a description identical to its summary',
                    $operation->method->forDisplay(),
                    $operation->pathUri,
                ),
                fixHint: 'Give the description more detail than the summary, or remove the redundant description.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'operation.summary-equals-description';
    }

    #[Override]
    public function level(): int
    {
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return 'Operation summary and description are identical (redundant).';
    }
}
