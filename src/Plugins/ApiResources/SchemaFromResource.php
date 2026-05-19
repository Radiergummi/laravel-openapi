<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\NullableSchema;
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
 * other class-string field types are resolved via the injected resolver list
 * (which deliberately excludes {@see ResourceRefSchemaResolver} to avoid a
 * container construction cycle — see the plan's architecture note).
 */
final class SchemaFromResource
{
    /**
     * @param list<RefSchemaResolver> $refSchemaResolvers Registered ref resolvers, minus this plugin's own.
     */
    public function __construct(
        private readonly ComponentSchemaRegistry $registry,
        private readonly array $refSchemaResolvers = [],
    ) {}

    /**
     * Registers the resource as a component schema and returns the component key.
     *
     * @param class-string<JsonResource> $resourceClass
     */
    public function build(string $resourceClass): string
    {
        if ($this->registry->isInProgress($resourceClass)) {
            return $this->registry->reserveKey($resourceClass);
        }

        if ($this->registry->isRegisteredOrReserved($resourceClass)) {
            /** @var string $key */
            $key = $this->registry->keyFor($resourceClass);

            return $key;
        }

        $this->registry->markInProgress($resourceClass);

        $schema = $this->buildSchema($resourceClass);

        $this->registry->register($resourceClass, $schema);
        $this->registry->markComplete($resourceClass);

        /** @var string $key */
        $key = $this->registry->keyFor($resourceClass);

        return $key;
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

        $props = ['property' => $field->name];

        foreach ($field->descriptor()->toOpenApi() as $key => $value) {
            $props[$key] = $value;
        }

        $property = new OA\Property($props);

        if ($field->nullable === true) {
            NullableSchema::applyTo($property);
        }

        return $property;
    }

    /**
     * @param class-string $class
     */
    private function resolveClassRef(string $class): ?string
    {
        if (is_a($class, JsonResource::class, allow_string: true)) {
            /** @var class-string<JsonResource> $class */
            return $this->registry->qualifyKey($this->build($class));
        }

        foreach ($this->refSchemaResolvers as $resolver) {
            $ref = $resolver->resolveRef($class);

            if ($ref !== null) {
                return $ref;
            }
        }

        return null;
    }
}
