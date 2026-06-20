<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Contracts\Routing\ResourceTargetLocator;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;

use function sprintf;

/**
 * Flags a `#[ResourceField]` declared with no `type`; its schema cannot be derived, so the
 * field is emitted untyped.
 */
final class ResourceFieldTypeMissing implements Rule, OperationRuleVisitor
{
    public string $id = 'resource.field-type-missing';
    public Severity $severity = Severity::Underspecified;
    public string $description = 'A #[ResourceField] is declared without a resolvable type.';

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

        if ($target === null || $target->isAmbiguous) {
            return;
        }

        $resourceClass = $target->resourceClass;

        // A wrapped-model target uses the model's schema directly; no resource to inspect.
        if ($resourceClass === null) {
            return;
        }

        foreach ($context->reflectionCache->classAttributes($resourceClass, ResourceField::class) as $attribute) {
            $field = $attribute->newInstance();

            if ($field->type !== null) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf(
                    '#[ResourceField(\'%s\')] on %s has no type — the field is emitted untyped',
                    $field->name,
                    $resourceClass,
                ),
                fixHint: 'Add a type: a JSON-Schema scalar (\'string\', \'integer\', …) or a class-string for a nested $ref.',
            );
        }
    }



}
