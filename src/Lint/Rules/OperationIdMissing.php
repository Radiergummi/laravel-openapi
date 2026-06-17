<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\AddOperationIdFixer;
use Radiergummi\OpenApi\Lint\Fix\FixableRule;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Lint\Fix\RemoveAttributeFixer;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Support\Generator\OperationIdDeriver;

use function sprintf;

/**
 * Reports operations that have no operationId, which client generators and documentation tools
 * rely on to identify operations.
 */
final class OperationIdMissing implements FixableRule, OperationRuleVisitor
{
    public function __construct(
        private readonly OperationIdDeriver $operationIdDeriver = new OperationIdDeriver(),
    ) {}

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->operationId !== null) {
            return;
        }

        // Stamp the operationId inference would emit, plus the source member, so the fixer
        // synthesises exactly that. Only addressable when the operation has a real action behind it.
        $fixContext = [];

        if ($operation->descriptor !== null) {
            $fixContext = [
                ...RemoveAttributeFixer::contextForOperation($operation->descriptor),
                AddOperationIdFixer::CONTEXT_OPERATION_ID => $this->operationIdDeriver->derive(
                    $operation->descriptor,
                    $operation->method,
                ),
            ];
        }

        yield new Finding(
            ruleId: $this->id(),
            severity: $this->severity(),
            message: sprintf(
                'Operation %s %s has no operationId',
                $operation->method->forDisplay(),
                $operation->pathUri,
            ),
            fixHint: 'Add an operationId to the operation via #[Operation(operationId: "...")].',
            context: $fixContext,
        );
    }

    #[Override]
    public function fixer(): Fixer
    {
        return new AddOperationIdFixer();
    }

    #[Override]
    public function id(): string
    {
        return 'operation.id-missing';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Degraded;
    }

    #[Override]
    public function description(): string
    {
        return 'Operation has no operationId.';
    }
}
