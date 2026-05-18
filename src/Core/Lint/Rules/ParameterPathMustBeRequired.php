<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ParameterRule as ParameterRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ParameterNode;

use function sprintf;

/**
 * Reports path parameters that are not marked as required.
 *
 * The OpenAPI specification mandates that parameters with `in: "path"` MUST have `required: true`.
 * This rule enforces that constraint.
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
                $operation instanceof OperationNode ? $operation->method : '(unknown)',
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
