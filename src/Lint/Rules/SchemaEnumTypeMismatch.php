<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Lint\Visitors\FieldRule as FieldRuleVisitor;

use function gettype;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Reports when a field's `enum` values are incompatible with its declared `type` (e.g., an integer
 * enum containing string values).
 */
final class SchemaEnumTypeMismatch implements Rule, FieldRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkField(FieldNode $field, LintContext $context): iterable
    {
        if ($field->enum === null || $field->enum === []) {
            return;
        }

        if ($field->type === null) {
            return;
        }

        $validator = $this->validatorForType($field->type);

        if ($validator === null) {
            return;
        }

        foreach ($field->enum as $index => $value) {
            if ($validator($value)) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Field "%s" declares type "%s" but enum value at index %d is %s (%s)',
                    $field->name,
                    $field->type,
                    $index,
                    var_export($value, true),
                    gettype($value),
                ),
                location: new FindingLocation(jsonPointer: $field->pointer("enum/{$index}")),
                fixHint: sprintf(
                    'Change the enum value to match type "%s" or correct the schema type.',
                    $field->type,
                ),
            );
        }
    }

    /**
     * Returns null for types we do not validate (e.g., `object`, `array`).
     *
     * @return null|callable(mixed): bool
     */
    private function validatorForType(string $type): ?callable
    {
        return match ($type) {
            'integer' => is_int(...),
            'number' => static fn(mixed $v): bool => is_int($v) || is_float($v),
            'string' => is_string(...),
            'boolean' => is_bool(...),
            default => null,
        };
    }

    #[Override]
    public function id(): string
    {
        return 'schema.enum-type-mismatch';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return "Schema enum contains values that don't match the declared type.";
    }
}
