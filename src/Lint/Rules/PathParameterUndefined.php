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
    public string $id = 'path.parameter-undefined';
    public Severity $severity = Severity::Broken;
    public string $description = "A declared path parameter doesn't appear in the path template.";

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        foreach ($operation->parameters as $parameter) {
            if (
                str_contains($operation->pathUri, "{{$parameter->name}}")
                || str_contains($operation->pathUri, "{{$parameter->name}?}")
            ) {
                continue;
            }

            $message = $parameter->required
                ? sprintf(
                    'Path parameter "%s" on %s %s has no corresponding {%s} placeholder in the path',
                    $parameter->name,
                    $operation->method->forDisplay(),
                    $operation->pathUri,
                    $parameter->name,
                )
                : sprintf(
                    'Optional path parameter "%s" on %s %s has no corresponding {%s?} placeholder in the path',
                    $parameter->name,
                    $operation->method->forDisplay(),
                    $operation->pathUri,
                    $parameter->name,
                );

            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: $message,
                fixHint: sprintf(
                    'Remove the parameter or add {%s} to the path template.',
                    $parameter->name . ($parameter->required ? '' : '?'),
                ),
            );
        }
    }



}
