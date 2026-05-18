<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Override;

use function sprintf;
use function str_contains;

/**
 * Reports path parameters declared on an operation that do not have a
 * corresponding `{name}` placeholder in the URI template.
 *
 * This is the reverse of `PathParameterUndeclared`: it catches parameters
 * defined in the operation that the path template doesn't use.
 */
final class PathParameterUndefined implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        foreach ($operation->parameters as $parameter) {
            if (
                ($parameter->required && str_contains($operation->pathUri, "{{$parameter->name}}"))
                || (!$parameter->required && str_contains($operation->pathUri, "{{$parameter->name}?}"))
            ) {
                continue;
            }

            $message = $parameter->required
                ? sprintf(
                    'Path parameter "%s" on %s %s has no corresponding {%s} placeholder in the path',
                    $parameter->name,
                    $operation->method,
                    $operation->pathUri,
                    $parameter->name,
                )
                : sprintf(
                    'Optional path parameter "%s" on %s %s has no corresponding {%s?} placeholder in the path',
                    $parameter->name,
                    $operation->method,
                    $operation->pathUri,
                    $parameter->name,
                );

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: $message,
                fixHint: sprintf(
                    'Remove the parameter or add {%s} to the path template.',
                    $parameter->name . ($parameter->required ? '' : '?'),
                ),
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'path.parameter-undefined';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return "A declared path parameter doesn't appear in the path template.";
    }
}
