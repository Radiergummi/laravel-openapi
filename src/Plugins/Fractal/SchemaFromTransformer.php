<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal;

use Closure;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;
use ReflectionClass;

use function class_exists;

/**
 * Builds the `OA\Schema` (type: object) for a Fractal transformer from its
 * class-level `#[TransformerField]` and `#[TransformerInclude]` attributes and
 * registers it as a component.
 *
 * Nested transformer-shaped field types (classes carrying `#[TransformerField]`)
 * recurse through {@see build()} directly; other class-string types resolve via
 * the injected resolver factory — a `Closure` returning the registered
 * resolvers minus this plugin's own {@see TransformerRefSchemaResolver}. The
 * list is lazy by design: the eager equivalent forms a cross-plugin
 * construction cycle with `SchemaFromResource`, because each plugin's own
 * `RefSchemaResolver` references the other plugin's `SchemaFrom*` builder
 * (filtering out only the same-plugin resolver is not enough). Invoking the
 * factory at use time lets the container finish constructing both sides first.
 */
final readonly class SchemaFromTransformer
{
    /**
     * @param Closure(): list<RefSchemaResolver> $refSchemaResolvers Lazy factory returning the registered ref resolvers, minus this plugin's own.
     */
    public function __construct(
        private ComponentSchemaRegistry $registry,
        private Closure $refSchemaResolvers,
    ) {}

    /**
     * Registers the transformer as a component schema and returns its key.
     *
     * @param class-string $transformerClass
     */
    public function build(string $transformerClass): string
    {
        return $this->registry->buildOnce(
            $transformerClass,
            fn(): OA\Schema => $this->buildSchema($transformerClass),
        );
    }

    /**
     * Registers the transformer and returns its qualified `$ref` string.
     *
     * @param class-string $transformerClass
     */
    public function buildRef(string $transformerClass): string
    {
        return $this->registry->qualifyKey($this->build($transformerClass));
    }

    /**
     * @param class-string $transformerClass
     */
    private function buildSchema(string $transformerClass): OA\Schema
    {
        $reflection = new ReflectionClass($transformerClass);

        /** @var list<OA\Property> $properties */
        $properties = [];

        /** @var list<string> $required */
        $required = [];

        foreach ($reflection->getAttributes(TransformerField::class) as $attribute) {
            $field = $attribute->newInstance();
            $properties[] = $this->buildFieldProperty($field);

            if (!$field->conditional) {
                $required[] = $field->name;
            }
        }

        foreach ($reflection->getAttributes(TransformerInclude::class) as $attribute) {
            $include = $attribute->newInstance();
            $properties[] = $this->buildIncludeProperty($include);

            if ($include->default) {
                $required[] = $include->name;
            }
        }

        $props = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $props['required'] = $required;
        }

        return new OA\Schema($props);
    }

    private function buildFieldProperty(TransformerField $field): OA\Property
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

    private function buildIncludeProperty(TransformerInclude $include): OA\Property
    {
        $ref = $include->transformer !== null && class_exists($include->transformer)
            ? $this->resolveClassRef($include->transformer)
            : null;

        return $ref !== null
            ? new OA\Property(['property' => $include->name, 'ref' => $ref])
            : new OA\Property(['property' => $include->name, 'type' => 'object']);
    }

    /**
     * @param class-string $class
     */
    private function resolveClassRef(string $class): ?string
    {
        if (new ReflectionClass($class)->getAttributes(TransformerField::class) !== []) {
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
