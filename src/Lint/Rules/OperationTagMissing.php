<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function sprintf;

/**
 * Reports operations that have no tags assigned.
 */
final class OperationTagMissing implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->tags === []) {
            yield new Finding(
                ruleId: $this->id(),
                severity: $this->severity(),
                message: sprintf(
                    'Operation %s %s has no tags',
                    $operation->method->forDisplay(),
                    $operation->pathUri,
                ),
                fixHint: 'Add at least one tag to the operation via #[Tag] or #[Operation(tags: [...])].',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'operation.tag-missing';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Degraded;
    }

    #[Override]
    public function description(): string
    {
        return 'Operation has no tags.';
    }
}
