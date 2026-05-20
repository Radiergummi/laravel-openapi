<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources;

use Closure;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use ReflectionClass;

use function class_exists;
use function is_a;

/**
 * Builds the `OA\Schema` (type: object) for an Eloquent API Resource from its
 * class-level `#[ResourceField]` attributes and registers it as a component.
 *
 * Nested `JsonResource` field types recurse through {@see build()} directly;
 * other class-string field types resolve via the injected resolver factory — a
 * `Closure` returning the registered resolvers minus this plugin's own
 * {@see ResourceRefSchemaResolver}. The list is lazy by design: the eager
 * equivalent forms a cross-plugin construction cycle with `SchemaFromTransformer`,
 * because each plugin's own `RefSchemaResolver` references the other plugin's
 * `SchemaFrom*` builder. Invoking the factory at use time lets the container
 * finish constructing both sides first.
 */
final readonly class SchemaFromResource
{
    /**
     * @param Closure(): list<RefSchemaResolver> $refSchemaResolvers Lazy factory returning the registered ref resolvers, minus this plugin's own.
     */
    public function __construct(
        private ComponentSchemaRegistry $registry,
        private Closure $refSchemaResolvers,
    ) {}

    /**
     * Registers the resource as a component schema and returns the component key.
     *
     * @param class-string<JsonResource> $resourceClass
     */
    public function build(string $resourceClass): string
    {
        return $this->registry->buildOnce($resourceClass, fn(): OA\Schema => $this->buildSchema($resourceClass));
    }

    /**
     * Registers the resource and returns its qualified `$ref` string.
     *
     * @param class-string<JsonResource> $resourceClass
     */
    public function buildRef(string $resourceClass): string
    {
        return $this->registry->qualifyKey($this->build($resourceClass));
    }

    /**
     * @param class-string<JsonResource> $resourceClass
     */
    private function buildSchema(string $resourceClass): OA\Schema
    {
        $reflection = new ReflectionClass($resourceClass);

        /** @var list<OA\Property> $properties */
        $properties = [];

        /** @var list<string> $required */
        $required = [];

        foreach ($reflection->getAttributes(ResourceField::class) as $attribute) {
            $field = $attribute->newInstance();
            $properties[] = $this->buildProperty($field);

            if (!$field->conditional) {
                $required[] = $field->name;
            }
        }

        $props = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $props['required'] = $required;
        }

        return new OA\Schema($props);
    }

    private function buildProperty(ResourceField $field): OA\Property
    {
        $type = $field->type;

        if ($type !== null && class_exists($type)) {
            $ref = $this->resolveClassRef($type);

            $property = $ref !== null
                ? new OA\Property(['property' => $field->name, 'ref' => $ref])
                : new OA\Property(['property' => $field->name, 'type' => 'object']);

            if ($field->description !== null) {
                $property->description = $field->description;
            }

            return $property;
        }

        $property = new OA\Property(['property' => $field->name]);
        $field->descriptor()->applyTo($property);

        return $property;
    }

    /**
     * @param class-string $class
     */
    private function resolveClassRef(string $class): ?string
    {
        if (is_a($class, JsonResource::class, allow_string: true)) {
            /** @var class-string<JsonResource> $class */
            return $this->buildRef($class);
        }

        foreach (($this->refSchemaResolvers)() as $resolver) {
            $ref = $resolver->resolveRef($class);

            if ($ref !== null) {
                return $ref;
            }
        }

        return null;
    }
}
