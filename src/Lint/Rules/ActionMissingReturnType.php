<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Attributes\ResponseResource;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionNamedType;

use function in_array;
use function sprintf;

/**
 * Reports actions with no usable return type and no response attribute, the most common reason an
 * operation ends up with no response schema. Stays silent the moment either signal exists.
 */
final class ActionMissingReturnType implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->webhook || $operation->descriptor === null) {
            return;
        }

        $descriptor = $operation->descriptor;

        if ($this->hasResponseAttribute($descriptor) || $this->hasUsableReturnType($descriptor)) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            severity: $this->severity(),
            message: sprintf(
                '%s::%s() has no return type or response attribute, so no response schema can be inferred',
                $descriptor->controller?->getShortName() ?? '(unknown)',
                $descriptor->method?->getName() ?? '(unknown)',
            ),
            fixHint: 'Add a return type to the action, or annotate it with #[Response] / #[ResponseResource].',
        );
    }

    private function hasResponseAttribute(ActionDescriptor $descriptor): bool
    {
        return $descriptor->attributeInstances(Response::class) !== []
            || $descriptor->attributeInstances(ResponseResource::class) !== [];
    }

    /**
     * A return type is "usable" when declared and not `mixed`, `void`, or `never`.
     */
    private function hasUsableReturnType(ActionDescriptor $descriptor): bool
    {
        $returnType = $descriptor->actionReflector?->getReturnType();

        if ($returnType === null) {
            return false;
        }

        if (!$returnType instanceof ReflectionNamedType) {
            // Union/intersection types are still a declared shape.
            return true;
        }

        return !in_array($returnType->getName(), ['mixed', 'void', 'never'], true);
    }

    #[Override]
    public function id(): string
    {
        return 'operation.return-type-missing';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Inconsistent;
    }

    #[Override]
    public function description(): string
    {
        return 'Action has no typed return value or response attribute, so no response schema can be inferred.';
    }
}
