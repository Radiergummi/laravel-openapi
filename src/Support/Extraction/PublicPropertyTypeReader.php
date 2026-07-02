<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use DateTimeInterface;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Routing\UrlRoutable;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\Generator\NullableSchema;
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
 * A plain object leaf (a non-format, non-enum class) is refused by default, since the engine can
 * only stub it. A caller that can recurse into such a class supplies a `$leafClassSchema` callback:
 * when it returns a schema for the object's class name that schema is used (e.g. a `$ref`), and when
 * it returns null the property is still refused. Without a callback the behaviour is unchanged.
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
     * @param class-string                      $class
     * @param null|callable(string): ?OA\Schema $leafClassSchema resolves a plain-object property's
     *                                                           class name to a schema (e.g. a
     *                                                           `$ref`); null refuses the property
     *
     * @throws ReflectionException
     */
    public function propertyFor(
        string $class,
        string $propertyName,
        ?callable $leafClassSchema = null,
    ): ?OA\Property {
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

        $schema = $this->schemaForType($type, $leafClassSchema);

        if ($schema === null) {
            return null;
        }

        return copy_schema_fields($schema, new OA\Property(['property' => $propertyName]));
    }

    /**
     * The schema for a resolved property type, or null when it cannot be typed without guessing.
     *
     * A modelled type (scalar, backed enum, DateTime/UUID/UrlRoutable) is handled by the engine,
     * which also applies nullability. A plain object leaf is refused unless a `$leafClassSchema`
     * callback resolves it (nullability then wrapped here, since the callback is class-only).
     *
     * @param null|callable(string): ?OA\Schema $leafClassSchema
     */
    private function schemaForType(Type $type, ?callable $leafClassSchema): ?OA\Schema
    {
        if ($this->isModelledType($type)) {
            return $this->jsonSchemaFromType->fromType($type);
        }

        if ($leafClassSchema === null) {
            return null;
        }

        $inner = $type instanceof NullableType ? $type->getWrappedType() : $type;

        if (!$inner instanceof ObjectType) {
            return null;
        }

        $schema = $leafClassSchema($inner->getClassName());

        if ($schema === null) {
            return null;
        }

        return $type instanceof NullableType ? NullableSchema::wrap($schema) : $schema;
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
