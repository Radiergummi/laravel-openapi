<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use BackedEnum;
use Override;
use Radiergummi\OpenApi\Core\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
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
    /**
     * @return iterable<Finding>
     */
    #[Override]
    protected function inspectField(
        FieldAttribute $field,
        ReflectionProperty $property,
        OperationNode $operation,
    ): iterable {
        if ($field->enum === null) {
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

        // Normalize both sides to strings so that integer-backed enum cases
        // compare equal regardless of how the field attribute spelled them — a
        // BackedEnum instance, the bare scalar `1`, or the string `"1"` all
        // collapse to the same value. Without this, sort()+=== would report
        // spurious mismatches on mixed int/string lists.
        $enumCaseValues = array_map(
            static fn(BackedEnum $case): string => (string) $case->value,
            $typeName::cases(),
        );

        $fieldValues = array_map(
            static fn(mixed $value): string => $value instanceof BackedEnum
                ? (string) $value->value
                : (string) $value,
            $field->enum,
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
            ruleId: $this->id(),
            level: $this->level(),
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

    #[Override]
    public function id(): string
    {
        return 'field.enum-mismatch';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return "Enum value type doesn't match the field's declared type.";
    }
}
