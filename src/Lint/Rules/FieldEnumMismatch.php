<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use BackedEnum;
use Override;
use Radiergummi\OpenApi\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use ReflectionNamedType;
use ReflectionProperty;

use function array_diff;
use function array_map;
use function implode;
use function is_subclass_of;
use function sort;
use function sprintf;

final class FieldEnumMismatch extends AbstractFieldRule
{
    public string $id = 'field.enum-mismatch';
    public Severity $severity = Severity::Broken;
    public string $description = "Enum value type doesn't match the field's declared type.";


    /**
     * @return iterable<Finding>
     */
    #[Override]
    protected function inspectField(
        FieldAttribute $field,
        ReflectionProperty $property,
        OperationNode $operation,
    ): iterable {
        $enum = $field->explicitEnum();

        if ($enum === null) {
            return;
        }

        $propertyType = $property->getType();

        if (!$propertyType instanceof ReflectionNamedType || $propertyType->isBuiltin()) {
            return;
        }

        $typeName = $propertyType->getName();

        if (!is_subclass_of($typeName, BackedEnum::class)) {
            return;
        }

        // Normalize to strings so integer-backed cases compare equal regardless of int/string
        // spelling (BackedEnum instance, bare `1`, and `"1"` all collapse to the same value).
        $enumCaseValues = array_map(
            static fn(BackedEnum $case): string => (string) $case->value,
            $typeName::cases(),
        );

        $fieldValues = array_map(
            static fn(mixed $value): string
                => $value instanceof BackedEnum
                ? (string) $value->value
                : (string) $value,
            $enum,
        );

        sort($enumCaseValues, SORT_STRING);
        sort($fieldValues, SORT_STRING);

        if ($enumCaseValues === $fieldValues) {
            return;
        }

        $missing = array_diff($enumCaseValues, $fieldValues);
        $extra = array_diff($fieldValues, $enumCaseValues);

        $details = [];

        if ($missing !== []) {
            $details[] = sprintf('missing [%s]', implode(', ', $missing));
        }

        if ($extra !== []) {
            $details[] = sprintf('extra [%s]', implode(', ', $extra));
        }

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                '#[%s] enum values on %s::$%s do not match %s cases: %s',
                $this->attributeName($field),
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                $typeName,
                implode('; ', $details),
            ),
            fixHint: sprintf(
                'Update the #[%s(enum: [...])] to match all %s cases exactly.',
                $this->attributeName($field),
                $typeName,
            ),
        );
    }


}
