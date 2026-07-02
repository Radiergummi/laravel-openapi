<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Container\Attributes\Scoped;
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
use Symfony\Component\TypeInfo\Type\EnumType;
use Symfony\Component\TypeInfo\Type\ObjectType;

use function is_a;
use function Radiergummi\OpenApi\copy_schema_fields;

/**
 * The baseline primary-response resolver for statically-typed controller returns: a documented
 * `@return array{…}`, a scalar, a backed enum, a map, or a typed collection of those. It runs after
 * every convention plugin (Spatie Data, Eloquent, API Resource, Fractal, paginator), so it fires
 * only when none of them claims the action, the language-level fallback the "type your returns to
 * get response schemas" story promises. Works with the Core plugin disabled.
 *
 * It never invents a schema: a return it cannot map without guessing (untyped / `mixed` / `void`, an
 * undeclared collection element, a plain object leaf) degrades to null, leaving the default
 * `200 OK`. The "unmapped object" stub {@see JsonSchemaFromType} would emit for a plain object leaf
 * is never surfaced, top-level or nested.
 *
 * @internal
 */
#[Scoped]
final readonly class TypedReturnResponseResolver implements PrimaryResponseResolver
{
    public function __construct(
        private ReturnShapeResolver $shapeResolver,
        private JsonSchemaFromType $jsonSchemaFromType,
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
     * A real schema for a type the engine maps without inventing a stub, or null to degrade. A
     * top-level plain object (not a DateTime/UUID/UrlRoutable format object) has no baseline schema
     * source in this stage, so it degrades; a plain object reached nested through the engine flags
     * the whole return as degraded rather than letting the "unmapped object" stub surface.
     */
    private function mappableSchema(Type $type): ?OA\Schema
    {
        // A plain top-level object (not an enum, not a DateTime/UUID format object) has no baseline
        // schema source in this stage.
        if (
            $type instanceof ObjectType
            && !$type instanceof EnumType
            && !$this->mapsToFormat($type->getClassName())
        ) {
            return null;
        }

        $degraded = false;
        $schema = $this->jsonSchemaFromType->fromType(
            $type,
            function (string $className) use (&$degraded): ?OA\Schema {
                // Defer to the engine for the leaves it maps itself: format objects and backed enums.
                if ($this->mapsToFormat($className) || is_a($className, BackedEnum::class, allow_string: true)) {
                    return null;
                }

                // Any other plain object leaf has no baseline schema: flag the degrade and hand back
                // an empty schema so the engine never reaches its "unmapped object" stub.
                $degraded = true;

                return new OA\Schema([]);
            },
        );

        return $degraded ? null : $schema;
    }

    private function mapsToFormat(string $className): bool
    {
        return is_a($className, DateTimeInterface::class, allow_string: true)
            || is_a($className, UuidInterface::class, allow_string: true)
            || is_a($className, UrlRoutable::class, allow_string: true);
    }
}
