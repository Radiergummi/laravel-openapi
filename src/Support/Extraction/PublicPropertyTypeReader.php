<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use DateTimeInterface;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Routing\UrlRoutable;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Ramsey\Uuid\UuidInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\BackedEnumType;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

use function class_exists;
use function is_a;
use function Radiergummi\OpenApi\copy_schema_fields;

/**
 * Schema for a statically-typed public property of a plain class, by reflection.
 *
 * Plugin-agnostic and Model-free: maps a property's declared PHP type the same way the Eloquent
 * accessor path does ({@see TypeResolver} → {@see JsonSchemaFromType}), but refuses (returns null)
 * for anything that cannot be typed without guessing — no declared type, a union/intersection, a
 * non-public or static property, a missing class, or a type that would only map to the
 * "Unmapped type" placeholder. Refusal lets the caller stay unconstrained rather than emit a
 * `oneOf` or a placeholder schema.
 *
 * @internal
 */
#[Scoped]
final readonly class PublicPropertyTypeReader
{
    public function __construct(
        private JsonSchemaFromType $jsonSchemaFromType,
        private TypeResolver $typeResolver,
    ) {}

    /**
     * Returns a named property schema for `$class::$propertyName`, or null when it cannot be typed
     * without guessing.
     *
     * @param class-string $class
     *
     * @throws ReflectionException
     */
    public function propertyFor(string $class, string $propertyName): ?OA\Property
    {
        if (!class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->hasProperty($propertyName)) {
            return null;
        }

        $property = $reflection->getProperty($propertyName);

        if (!$property->isPublic() || $property->isStatic()) {
            return null;
        }

        $declaredType = $property->getType();

        // Only a single named type is modelled; union/intersection/no-type refuse.
        if (!$declaredType instanceof ReflectionNamedType) {
            return null;
        }

        try {
            $type = $this->typeResolver->resolve($declaredType);
        } catch (UnsupportedException) {
            return null;
        }

        if (!$this->isModelledType($type)) {
            return null;
        }

        return copy_schema_fields(
            $this->jsonSchemaFromType->fromType($type),
            new OA\Property(['property' => $propertyName]),
        );
    }

    /**
     * Whether {@see JsonSchemaFromType} maps this type to a concrete schema rather than the
     * `oneOf` / "Unmapped type" fallbacks. A plain nullable wrapper of a modelled type is fine.
     */
    private function isModelledType(Type $type): bool
    {
        if ($type instanceof NullableType) {
            return $this->isModelledType($type->getWrappedType());
        }

        if ($type instanceof BackedEnumType) {
            return true;
        }

        if ($type instanceof BuiltinType) {
            return $type->isIdentifiedBy(...self::MODELLED_BUILTINS);
        }

        // Only the object types JsonSchemaFromType maps to a format are modelled; any other
        // object would emit the "Unmapped object type" placeholder, which is not never-wrong.
        if ($type instanceof ObjectType) {
            $className = $type->getClassName();

            return is_a($className, DateTimeInterface::class, allow_string: true)
                || is_a($className, UuidInterface::class, allow_string: true)
                || is_a($className, UrlRoutable::class, allow_string: true);
        }

        return false;
    }

    /**
     * Builtin identifiers {@see JsonSchemaFromType::fromBuiltinType} maps to a concrete schema.
     */
    private const array MODELLED_BUILTINS = ['string', 'int', 'float', 'bool', 'array'];
}
