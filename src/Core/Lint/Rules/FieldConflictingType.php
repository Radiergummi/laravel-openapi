<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Radiergummi\OpenApi\Core\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Override;
use ReflectionNamedType;
use ReflectionProperty;

use function sprintf;

final class FieldConflictingType extends AbstractFieldRule
{
    /**
     * Maps PHP built-in type names to OpenAPI type strings.
     *
     * @var array<string, string>
     */
    private const PHP_TO_OPENAPI = [
        'int' => 'integer',
        'float' => 'number',
        'bool' => 'boolean',
        'string' => 'string',
        'array' => 'array',
    ];

    /**
     * @return iterable<Finding>
     */
    #[Override]
    protected function inspectField(
        FieldAttribute $field,
        ReflectionProperty $property,
        OperationNode $operation,
    ): iterable {
        if ($field->type === null) {
            return;
        }

        $propertyType = $property->getType();

        if (!$propertyType instanceof ReflectionNamedType || !$propertyType->isBuiltin()) {
            return;
        }

        $phpTypeName = $propertyType->getName();
        $expectedOpenApiType = self::PHP_TO_OPENAPI[$phpTypeName] ?? null;

        if ($expectedOpenApiType === null || $expectedOpenApiType === $field->type) {
            return;
        }

        $attributeName = $this->attributeName($field);

        $message = sprintf(
            'Property %s::$%s has PHP type "%s" (OpenAPI: "%s") but #[%s] declares type "%s"',
            $property->getDeclaringClass()->getName(),
            $property->getName(),
            $phpTypeName,
            $expectedOpenApiType,
            $attributeName,
            $field->type,
        );

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: $message,
            fixHint: sprintf(
                'Change the #[%s] type to "%s" or adjust the PHP type hint.',
                $attributeName,
                $expectedOpenApiType,
            ),
        );
    }

    #[Override]
    public function id(): string
    {
        return 'field.conflicting-type';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'Field declares conflicting type and format values.';
    }
}
