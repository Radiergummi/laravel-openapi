<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources;

use Closure;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\Description as DescriptionAttribute;
use Radiergummi\OpenApi\Attributes\Summary as SummaryAttribute;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use ReflectionClass;
use ReflectionException;

use function assert;
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
     * @param Closure(): list<RefSchemaResolver> $refSchemaResolvers Lazy factory returning the registered ref
     *                                                               resolvers, minus this plugin's own.
     */
    public function __construct(
        private ComponentSchemaRegistry $registry,
        private Closure $refSchemaResolvers,
    ) {}

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
     * Registers the resource as a component schema and returns the component key.
     *
     * @param class-string<JsonResource> $resourceClass
     */
    public function build(string $resourceClass): string
    {
        return $this->registry->buildOnce($resourceClass, fn(): OA\Schema => $this->buildSchema($resourceClass));
    }

    /**
     * @param class-string<JsonResource> $resourceClass
     *
     * @throws ReflectionException
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

        $title = $this->readClassAttributeValue($reflection, SummaryAttribute::class);

        if ($title !== null) {
            $props['title'] = $title;
        }

        $description = $this->readClassAttributeValue($reflection, DescriptionAttribute::class);

        if ($description !== null) {
            $props['description'] = $description;
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

            // Route description through the field's descriptor so inline directives are stripped
            // — matches the scalar branch below. Example/enum directives don't make sense on a
            // `$ref` schema, so only the cleaned description is propagated.
            $description = $field->descriptor()->description;

            if ($description !== null) {
                $property->description = $description;
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

    /**
     * @param ReflectionClass<JsonResource>                       $reflection
     * @param class-string<DescriptionAttribute|SummaryAttribute> $attribute
     */
    private function readClassAttributeValue(ReflectionClass $reflection, string $attribute): ?string
    {
        $attrs = $reflection->getAttributes($attribute);

        if ($attrs === []) {
            return null;
        }

        $instance = $attrs[0]->newInstance();
        assert($instance instanceof SummaryAttribute || $instance instanceof DescriptionAttribute);

        return $instance->value;
    }
}
