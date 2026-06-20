<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\FixableRule;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Lint\Fix\MemberKind;
use Radiergummi\OpenApi\Lint\Fix\RemoveAttributeFixer;
use Radiergummi\OpenApi\Lint\Fix\RemoveMode;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use ReflectionProperty;

use function sprintf;

final class FieldNoEffect extends AbstractFieldRule implements FixableRule
{
    public string $id = 'field.no-effect';
    public Severity $severity = Severity::Inconsistent;
    public string $description = 'A field attribute was applied but has no visible effect on the schema.';


    /** Removes the no-op attribute; location is identified via context keys stamped by {@see AbstractFieldRule}. */
    #[Override]
    public function fixer(): Fixer
    {
        return new RemoveAttributeFixer(
            attribute: FieldAttribute::class,
            member: MemberKind::Property,
            mode: RemoveMode::RemoveAll,
        );
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    protected function inspectField(
        FieldAttribute $field,
        ReflectionProperty $property,
        OperationNode $operation,
    ): iterable {
        if (!$this->isNoOp($field)) {
            return;
        }

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                '#[%s] on %s::$%s has no parameters set — the attribute has no effect',
                $this->attributeName($field),
                $property->getDeclaringClass()->getName(),
                $property->getName(),
            ),
            fixHint: sprintf(
                'Remove the #[%s] attribute or set at least one parameter (e.g., description).',
                $this->attributeName($field),
            ),
        );
    }

    private function isNoOp(FieldAttribute $field): bool
    {
        return $field->title === null
            && $field->description === null
            && $field->explicitExample() === null
            && $field->type === null
            && $field->format === null
            && $field->nullable === null
            && $field->default === null
            && $field->explicitEnum() === null
            && $field->minimum === null
            && $field->maximum === null
            && $field->exclusiveMinimum === null
            && $field->exclusiveMaximum === null
            && $field->multipleOf === null
            && $field->minLength === null
            && $field->maxLength === null
            && $field->pattern === null
            && $field->minItems === null
            && $field->maxItems === null
            && $field->uniqueItems === null
            && $field->readOnly === null
            && $field->writeOnly === null
            && $field->conditional === false;
    }


}
