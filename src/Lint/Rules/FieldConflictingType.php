<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
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
    private const array PHP_TO_OPENAPI = [
        'int' => 'integer',
        'float' => 'number',
        'bool' => 'boolean',
        'string' => 'string',
        'array' => 'array',
    ];

    #[Override]
    public function description(): string
    {
        return 'Field declares conflicting type and format values.';
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
            severity: $this->severity(),
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
    public function severity(): Severity
    {
        return Severity::Degraded;
    }
}
