<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Lint\Visitors\ParameterRule as ParameterRuleVisitor;

use function sprintf;

/**
 * Reports path parameters missing `required: true` (mandated by OAS for `in: "path"`).
 */
final class ParameterPathMustBeRequired implements Rule, ParameterRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkParameter(ParameterNode $parameter, LintContext $context): iterable
    {
        if ($parameter->required === true) {
            return;
        }

        $operation = $parameter->parent();

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Path parameter "%s" on %s %s must be required',
                $parameter->name,
                $operation instanceof OperationNode ? $operation->method->forDisplay() : '(unknown)',
                $operation instanceof OperationNode ? $operation->pathUri : '(unknown)',
            ),
            fixHint: 'Set required=true on all path parameters (OAS 3.x requirement).',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'parameter.path-must-be-required';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return 'Path parameter is not marked required: true.';
    }
}
