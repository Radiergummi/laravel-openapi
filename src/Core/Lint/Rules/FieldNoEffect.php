<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use ReflectionProperty;

use function sprintf;

final class FieldNoEffect extends AbstractFieldRule
{
    #[Override]
    public function description(): string
    {
        return 'A field attribute was applied but has no visible effect on the schema.';
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
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '#[%s] on %s::$%s has no parameters set — the attribute has no effect',
                $this->attributeName($field),
                $property->getDeclaringClass()->getName(),
                $property->getName(),
            ),
            fixHint: sprintf(
                'Remove the #[%s] attribute or set at least one parameter (e.g. description).',
                $this->attributeName($field),
            ),
        );
    }

    private function isNoOp(FieldAttribute $field): bool
    {
        return $field->title === null
            && $field->description === null
            && $field->example === null
            && $field->type === null
            && $field->format === null
            && $field->nullable === null
            && $field->default === null
            && $field->enum === null
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

    #[Override]
    public function id(): string
    {
        return 'field.no-effect';
    }

    #[Override]
    public function level(): int
    {
        return 3;
    }
}
