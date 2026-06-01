<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Routing\ResourceTargetLocator;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;

use function sprintf;

/**
 * Flags a `#[ResourceField]` declared with no `type` — its schema cannot be derived, so the
 * field is emitted untyped.
 */
final readonly class ResourceFieldTypeMissing implements Rule, OperationRuleVisitor
{
    public function __construct(
        private ResourceTargetLocator $locator,
    ) {}

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->webhook || $operation->descriptor === null) {
            return;
        }

        $target = $this->locator->locate($operation->descriptor);

        if ($target === null || $target->isAmbiguous()) {
            return;
        }

        /** @var class-string $resourceClass */
        $resourceClass = $target->resourceClass;

        foreach ($context->reflectionCache->classAttributes($resourceClass, ResourceField::class) as $attribute) {
            $field = $attribute->newInstance();

            if ($field->type !== null) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    '#[ResourceField(\'%s\')] on %s has no type — the field is emitted untyped',
                    $field->name,
                    $resourceClass,
                ),
                fixHint: 'Add a type: a JSON-Schema scalar (\'string\', \'integer\', …) or a class-string for a nested $ref.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'resource.field-type-missing';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'A #[ResourceField] is declared without a resolvable type.';
    }
}
