<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use ArrayAccess;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Routing\UrlRoutable;
use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\Generator\NullableSchema;
use Radiergummi\OpenApi\Support\Routing\ReturnContainer;
use Radiergummi\OpenApi\Support\Routing\ReturnShape;
use Radiergummi\OpenApi\Support\Routing\ReturnShapeResolver;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\EnumType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Traversable;

use function is_a;
use function Radiergummi\OpenApi\copy_schema_fields;

/**
 * The baseline primary-response resolver for statically-typed controller returns: a plain typed DTO,
 * a documented `@return array{…}`, a scalar, a backed enum, a map, or a typed collection of those. It
 * runs after every convention plugin (Spatie Data, Eloquent, API Resource, Fractal, paginator), so it
 * fires only when none of them claims the action, the language-level fallback the "type your returns
 * to get response schemas" story promises. Works with the Core plugin disabled.
 *
 * It never invents a schema: a return it cannot map without guessing (untyped / `mixed` / `void`, an
 * undeclared collection element, a plain object with no usable public property) degrades to null,
 * leaving the default `200 OK`. The "unmapped object" stub {@see JsonSchemaFromType} would emit for a
 * plain object leaf is never surfaced; a nested object that cannot be built stays unconstrained.
 *
 * @internal
 */
#[Scoped]
final readonly class TypedReturnResponseResolver implements PrimaryResponseResolver
{
    public function __construct(
        private ReturnShapeResolver $shapeResolver,
        private JsonSchemaFromType $jsonSchemaFromType,
        private SchemaFromPublicProperties $schemaFromPublicProperties,
    ) {}

    #[Override]
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        $reflector = $descriptor->actionReflector;

        if ($reflector === null) {
            return null;
        }

        $schema = $this->schemaForShape($this->shapeResolver->describe($reflector));

        if ($schema === null) {
            return null;
        }

        return new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [MediaType::Json->schema($schema)],
        ]);
    }

    private function schemaForShape(ReturnShape $shape): ?OA\Schema
    {
        // Paginator envelopes are a Laravel convention the Core paginator resolver owns; defer.
        if ($shape->container === ReturnContainer::Paginated || $shape->itemType === null) {
            return null;
        }

        // A bare `mixed` / `void` / `never` return carries no body shape; the engine would map it to
        // an empty or "unmapped" stub, so degrade instead. (A `mixed` *element* of a list or shape
        // still maps to an unconstrained item.)
        if (
            $shape->container === ReturnContainer::Single
            && $shape->itemType instanceof BuiltinType
            && $shape->itemType->isIdentifiedBy(
                TypeIdentifier::MIXED,
                TypeIdentifier::VOID,
                TypeIdentifier::NEVER,
                TypeIdentifier::NULL,
            )
        ) {
            return null;
        }

        $inner = $shape->container === ReturnContainer::ListOf
            ? $this->listSchema($shape->itemType)
            : $this->mappableSchema($shape->itemType);

        if ($inner === null) {
            return null;
        }

        return $shape->nullable ? NullableSchema::wrap($inner) : $inner;
    }

    private function listSchema(Type $elementType): ?OA\Schema
    {
        $element = $this->mappableSchema($elementType);

        if ($element === null) {
            return null;
        }

        $items = new OA\Items([]);
        copy_schema_fields($element, $items);

        return new OA\Schema(['type' => 'array', 'items' => $items]);
    }

    /**
     * A real schema for a type, or null to degrade. A top-level plain object is built directly from
     * its public properties and degrades to null when none are usable; every other kind goes through
     * the engine, whose plain-object leaves are resolved (or left unconstrained) by the callback so
     * its "unmapped object" stub is never surfaced.
     */
    private function mappableSchema(Type $type): ?OA\Schema
    {
        if ($type instanceof ObjectType && !$type instanceof EnumType) {
            $className = $type->getClassName();

            // A collection/resource/paginator wrapper is shaped by its elements, a convention plugin's
            // job; it must degrade here even though it may also look like a format object (some
            // implement UrlRoutable). Checked before the format deferral for that reason.
            if ($this->isContainerLike($className)) {
                return null;
            }

            // A top-level plain object is built directly, degrading on null rather than round-tripping
            // through the engine and sniffing for its "unmapped object" stub.
            if (!$this->mapsToFormat($className)) {
                $reference = $this->schemaFromPublicProperties->buildRef($className);

                return $reference === null ? null : new OA\Schema(['ref' => $reference]);
            }
        }

        return $this->jsonSchemaFromType->fromType($type, $this->leafCallback());
    }

    /**
     * The engine's leaf callback for the array-shape / list / map paths: leave a container/resource
     * wrapper unconstrained, defer format objects and backed enums to the engine, build a nested plain
     * object into its own component, and leave an unbuildable nested object unconstrained (never the
     * engine's "unmapped object" stub).
     *
     * @return callable(string): ?OA\Schema
     */
    private function leafCallback(): callable
    {
        return function (string $className): ?OA\Schema {
            if ($this->isContainerLike($className)) {
                return new OA\Schema([]);
            }

            if ($this->mapsToFormat($className) || is_a($className, BackedEnum::class, allow_string: true)) {
                return null;
            }

            $reference = $this->schemaFromPublicProperties->buildRef($className);

            return $reference === null ? new OA\Schema([]) : new OA\Schema(['ref' => $reference]);
        };
    }

    private function mapsToFormat(string $className): bool
    {
        return is_a($className, DateTimeInterface::class, allow_string: true)
            || is_a($className, UuidInterface::class, allow_string: true)
            || is_a($className, UrlRoutable::class, allow_string: true);
    }

    /**
     * A collection/container object (Collection, resource collection, paginator, Data collection, …):
     * its JSON shape is its elements, not its public properties, so the baseline defers it.
     */
    private function isContainerLike(string $className): bool
    {
        return is_a($className, Traversable::class, allow_string: true)
            || is_a($className, ArrayAccess::class, allow_string: true);
    }
}
