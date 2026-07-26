<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use DateTimeInterface;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Routing\UrlRoutable;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\Generator\NullableSchema;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Ramsey\Uuid\UuidInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\ArrayShapeType;
use Symfony\Component\TypeInfo\Type\BackedEnumType;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\IntersectionType;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\Type\UnionType;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

use function class_exists;
use function is_a;
use function Radiergummi\OpenApi\copy_schema_fields;

/**
 * Schema for a statically-typed public property of a plain class, by reflection.
 *
 * Plugin-agnostic and Model-free: maps a property's declared type the same way the Eloquent accessor
 * path does ({@see JsonSchemaFromType}), but refuses (returns null) for anything that cannot be typed
 * without guessing — no declared type, a union/intersection, a non-public or static property, a
 * missing class, or a type that would only map to the "Unmapped type" placeholder. Refusal lets the
 * caller stay unconstrained rather than emit a `oneOf` or a placeholder schema.
 *
 * The property type is read from its `@var` docblock when that resolves to a mappable type, and from
 * the native reflection type otherwise, mirroring how the model path prefers a `@property` tag over a
 * cast. The `@var` read is conditional so the change stays additive: a property typed today (native)
 * never loses its schema to an unmappable `@var` refinement. Beyond scalars/enums/formats this covers
 * the structural PHPDoc shapes the engine maps — array shapes (`array{…}`), lists, and maps.
 *
 * A plain object leaf (a non-format, non-enum class) is refused by default, since the engine can only
 * stub it. A caller that can recurse into such a class supplies a `$leafClassSchema` callback: when it
 * returns a schema for the object's class name that schema is used (e.g. a `$ref`), and when it
 * returns null the property is still refused. Without a callback the behaviour is unchanged.
 *
 * @internal
 */
#[Scoped]
final readonly class PublicPropertyTypeReader
{
    public function __construct(
        private JsonSchemaFromType $jsonSchemaFromType,
        private TypeResolver $typeResolver,
        private DocBlockParser $docBlockParser,
        private TypeNodeResolver $typeNodeResolver,
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

        // Prefer the `@var` type only when it resolves to a mappable type; otherwise fall back to the
        // native type, so a property typed today never loses its schema to an unmappable refinement.
        $type = $this->typeFromVarTag($property, $reflection, $leafClassSchema)
            ?? $this->typeFromNativeType($property);

        if ($type === null) {
            return null;
        }

        $schema = $this->schemaForType($type, $leafClassSchema);

        if ($schema === null) {
            return null;
        }

        return copy_schema_fields($schema, new OA\Property(['property' => $propertyName]));
    }

    /**
     * The property's `@var` type, but only when it resolves and is mappable; null otherwise (so the
     * caller falls back to the native type). A promoted property carries no docblock (the tag lives on
     * the constructor `@param`), so this yields null there and the native type covers it.
     *
     * @param ReflectionClass<object>           $reflection
     * @param null|callable(string): ?OA\Schema $leafClassSchema
     */
    private function typeFromVarTag(
        ReflectionProperty $property,
        ReflectionClass $reflection,
        ?callable $leafClassSchema,
    ): ?Type {
        $docComment = $property->getDocComment();

        if ($docComment === false) {
            return null;
        }

        $varType = $this->docBlockParser->parse($docComment)->varType();

        if ($varType === null) {
            return null;
        }

        $type = $this->typeNodeResolver->toType($varType, $reflection);

        if ($type === null || !$this->isMappable($type, $leafClassSchema)) {
            return null;
        }

        return $type;
    }

    /**
     * The property's native reflection type, or null when it is untyped or a union/intersection (which
     * only a `@var` refinement could type). Mappability is left to {@see schemaForType}, preserving the
     * existing native refusals.
     */
    private function typeFromNativeType(ReflectionProperty $property): ?Type
    {
        $declaredType = $property->getType();

        if (!$declaredType instanceof ReflectionNamedType) {
            return null;
        }

        try {
            return $this->typeResolver->resolve($declaredType);
        } catch (UnsupportedException) {
            return null;
        }
    }

    /**
     * The schema for a resolved property type, or null when it cannot be typed without guessing.
     *
     * A structural shape (`array{…}`, list, map) threads the leaf callback so a nested leaf becomes a
     * `$ref`. A scalar/enum/format leaf is mapped without the callback, so a top-level `Carbon` stays a
     * `date-time` format instead of routing through a consumer `$ref` callback. A plain object leaf is
     * refused unless the callback resolves it (nullability then wrapped here).
     *
     * @param null|callable(string): ?OA\Schema $leafClassSchema
     */
    private function schemaForType(Type $type, ?callable $leafClassSchema): ?OA\Schema
    {
        $inner = $type instanceof NullableType ? $type->getWrappedType() : $type;

        if ($inner instanceof ArrayShapeType || $inner instanceof CollectionType) {
            return $this->isMappable($type, $leafClassSchema)
                ? $this->jsonSchemaFromType->fromType($type, $leafClassSchema)
                : null;
        }

        if ($this->isModelledLeaf($inner)) {
            return $this->jsonSchemaFromType->fromType($type);
        }

        if ($leafClassSchema === null || !$inner instanceof ObjectType) {
            return null;
        }

        $schema = $leafClassSchema($inner->getClassName());

        if ($schema === null) {
            return null;
        }

        return $type instanceof NullableType ? NullableSchema::wrap($schema) : $schema;
    }

    /**
     * Whether {@see JsonSchemaFromType} maps this type to a concrete schema rather than the `oneOf` /
     * "Unmapped type" fallbacks. Mirrors the engine's mapped branches: a nullable wrapper recurses;
     * a union/intersection refuses; an array shape or array-like collection is mappable when its
     * elements are; a plain object leaf counts as mappable only when a resolving callback is present
     * (presence, not invocation, so the check stays side-effect-free).
     *
     * @param null|callable(string): ?OA\Schema $leafClassSchema
     */
    private function isMappable(Type $type, ?callable $leafClassSchema): bool
    {
        if ($type instanceof NullableType) {
            return $this->isMappable($type->getWrappedType(), $leafClassSchema);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            return false;
        }

        // ArrayShapeType extends CollectionType, so it must be matched first.
        if ($type instanceof ArrayShapeType) {
            foreach ($type->getShape() as $field) {
                if (!$this->isMappableElement($field['type'], $leafClassSchema)) {
                    return false;
                }
            }

            $extraValueType = $type->isSealed() ? null : $type->getExtraValueType();

            return $extraValueType === null
                || $this->isMappableElement($extraValueType, $leafClassSchema);
        }

        if ($type instanceof CollectionType) {
            return $this->jsonSchemaFromType->isArrayLikeCollection($type)
                && $this->isMappableElement($type->getCollectionValueType(), $leafClassSchema);
        }

        return $this->isModelledLeaf($type)
            || ($type instanceof ObjectType && $leafClassSchema !== null);
    }

    /**
     * Like {@see isMappable}, but within a collection/array-shape a `mixed` element is mappable: the
     * engine emits an unconstrained items/additionalProperties schema for it rather than a placeholder.
     * A top-level `mixed` stays refused, since {@see isMappable} never calls this on the outer type.
     *
     * @param null|callable(string): ?OA\Schema $leafClassSchema
     */
    private function isMappableElement(Type $type, ?callable $leafClassSchema): bool
    {
        return $type->isIdentifiedBy(TypeIdentifier::MIXED)
            || $this->isMappable($type, $leafClassSchema);
    }

    /**
     * Whether the type is a scalar, backed enum, or format object (DateTime/UUID/UrlRoutable) the
     * engine maps to a concrete schema without a leaf callback. A unit (non-backed) enum arrives as an
     * {@see ObjectType} that matches no format, so it is not a modelled leaf (refused, as before).
     */
    private function isModelledLeaf(Type $type): bool
    {
        if ($type instanceof BackedEnumType) {
            return true;
        }

        if ($type instanceof BuiltinType) {
            return $type->isIdentifiedBy(...self::MODELLED_BUILTINS);
        }

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
