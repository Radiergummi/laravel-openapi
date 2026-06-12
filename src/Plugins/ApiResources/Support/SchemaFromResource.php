<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Support;

use Closure;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Attributes\Description as DescriptionAttribute;
use Radiergummi\OpenApi\Attributes\Summary as SummaryAttribute;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Plugins\ApiResources\Resolvers\ResourceRefSchemaResolver;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Extraction\FieldReferenceProperty;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use ReflectionClass;
use ReflectionException;

use function array_key_exists;
use function assert;
use function class_exists;
use function implode;
use function is_a;
use function sprintf;

/**
 * Builds the `OA\Schema` (type: object) for an Eloquent API Resource and registers it as a
 * component. Two sources compose per field: class-level `#[ResourceField]` attributes (always
 * authoritative for the fields they name) and the Tier-1 `toArray()` literal read by
 * {@see ResourceToArrayReader} — inferred fields the attributes do not cover follow them in
 * literal order.
 *
 * When neither source yields a field and the resource wraps a resolvable model
 * ({@see WrappedModelLocator}), {@see buildRef()} references the *model's* component directly —
 * the passthrough/dynamic fallback of the folded #98 design; no empty `*Resource` component is
 * created.
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
final class SchemaFromResource
{
    /**
     * Memoised passthrough/dynamic fallback decision per resource class (the wrapped model's
     * qualified ref, or null), so the degradation note fires once per generation run.
     *
     * @var array<class-string<JsonResource>, ?string>
     */
    private array $wrappedModelRefs = [];

    /**
     * @param Closure(): list<RefSchemaResolver> $refSchemaResolvers Lazy factory returning the registered ref
     *                                                               resolvers, minus this plugin's own.
     */
    public function __construct(
        private readonly ComponentSchemaRegistry $registry,
        private readonly Closure $refSchemaResolvers,
        private readonly ResourceToArrayReader $toArrayReader,
        private readonly WrappedModelLocator $wrappedModelLocator,
        private readonly EloquentModelToSchema $modelToSchema,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Registers the resource (or, for the passthrough/dynamic fallback, the model it wraps)
     * and returns the qualified `$ref` string.
     *
     * @param class-string<JsonResource> $resourceClass
     *
     * @throws ReflectionException
     */
    public function buildRef(string $resourceClass): string
    {
        return $this->wrappedModelRef($resourceClass)
            ?? $this->registry->qualifyKey($this->build($resourceClass));
    }

    /**
     * The wrapped model's component ref when the resource yields no fields of its own — no
     * `#[ResourceField]` attributes and no readable `toArray()` literal (a passthrough resource,
     * or a dynamic body that degrades here with a note). Composition is fallback-only by design:
     * a resource that declares or infers any field is authoritative, the model is ignored.
     *
     * @param class-string<JsonResource> $resourceClass
     *
     * @throws ReflectionException
     */
    private function wrappedModelRef(string $resourceClass): ?string
    {
        if (array_key_exists($resourceClass, $this->wrappedModelRefs)) {
            return $this->wrappedModelRefs[$resourceClass];
        }

        if (
            $this->declaredFields(new ReflectionClass($resourceClass)) !== []
            || $this->toArrayReader->read($resourceClass) !== null
        ) {
            return $this->wrappedModelRefs[$resourceClass] = null;
        }

        $modelClass = $this->wrappedModelLocator->locate($resourceClass);

        if ($modelClass === null) {
            return $this->wrappedModelRefs[$resourceClass] = null;
        }

        if ($this->toArrayReader->overridesToArray($resourceClass)) {
            $this->logger->notice(
                sprintf(
                    'toArray() of %s is not a single statically-readable return-array literal; '
                    . 'documenting the wrapped model schema (%s) instead. '
                    . 'Declare #[ResourceField] attributes to document the actual shape.',
                    $resourceClass,
                    $modelClass,
                ),
            );
        }

        return $this->wrappedModelRefs[$resourceClass]
            = $this->registry->qualifyKey($this->modelToSchema->build($modelClass));
    }

    /**
     * @param ReflectionClass<JsonResource> $reflection
     *
     * @return list<ResourceField>
     */
    private function declaredFields(ReflectionClass $reflection): array
    {
        $fields = [];

        foreach ($reflection->getAttributes(ResourceField::class) as $attribute) {
            $fields[] = $attribute->newInstance();
        }

        return $fields;
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

        /** @var array<string, true> $seenNames */
        $seenNames = [];

        foreach ($this->declaredFields($reflection) as $field) {
            if (isset($seenNames[$field->name])) {
                continue;
            }

            $seenNames[$field->name] = true;
            $properties[] = $this->buildProperty($field);

            if (!$field->conditional) {
                $required[] = $field->name;
            }
        }

        $inferred = $this->toArrayReader->read($resourceClass);

        if ($inferred !== null) {
            /** @var list<string> $unconstrainedKeys */
            $unconstrainedKeys = [];

            foreach ($inferred->fields as $field) {
                // A #[ResourceField] wins per field; a duplicate literal key keeps its first read.
                if (isset($seenNames[$field->name])) {
                    continue;
                }

                $seenNames[$field->name] = true;
                $properties[] = $this->propertyFromInferredField($field);

                if ($field->required) {
                    $required[] = $field->name;
                }

                if ($field->unconstrained) {
                    $unconstrainedKeys[] = $field->name;
                }
            }

            if ($unconstrainedKeys !== []) {
                $this->logger->notice(
                    sprintf(
                        'toArray() of %s has keys whose values could not be statically typed (%s); '
                        . 'they are documented as unconstrained properties. '
                        . 'Declare a #[ResourceField] for each to document its type.',
                        $resourceClass,
                        implode(', ', $unconstrainedKeys),
                    ),
                );
            }

            if ($inferred->hasUnreadableMergePayload) {
                $this->logger->notice(
                    sprintf(
                        'A merge()/mergeWhen() payload in %s::toArray() is not a literal array; '
                        . 'its keys are not documented. #[ResourceField] is the escape hatch.',
                        $resourceClass,
                    ),
                );
            }
        } elseif ($seenNames === [] && $this->toArrayReader->overridesToArray($resourceClass)) {
            // The wrapped-model fallback did not apply (buildRef would have short-circuited),
            // so a dynamic body with no declared fields leaves a genuinely empty schema.
            $this->logger->notice(
                sprintf(
                    'toArray() of %s is not a single statically-readable return-array literal and no '
                    . '#[ResourceField] or wrapped model (@mixin) is available; the response schema stays empty.',
                    $resourceClass,
                ),
            );
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

    /**
     * @throws ReflectionException
     */
    private function buildProperty(ResourceField $field): OA\Property
    {
        $type = $field->type;

        if ($type !== null && class_exists($type)) {
            return FieldReferenceProperty::build(
                $field->name,
                $field->descriptor()->description,
                $this->resolveClassRef($type),
            );
        }

        $property = new OA\Property(['property' => $field->name]);
        $field->descriptor()->applyTo($property);

        // array-of-$ref: a class-string `items:` on an array field resolves to `items: { $ref }`
        // via the same resolver chain as `type:`. The descriptor above already set `type: array`
        // plus any min/max/unique constraints and a scalar `items` placeholder — only the items
        // schema is overridden here. An unresolvable class degrades to a permissive object item,
        // symmetric with the single-ref `type: object` fallback above.
        if ($type === 'array' && $field->items !== null && class_exists($field->items)) {
            $ref = $this->resolveClassRef($field->items);

            $property->items = $ref !== null
                ? new OA\Items(['ref' => $ref])
                : new OA\Items(['type' => 'object']);
        }

        return $property;
    }

    /**
     * @param class-string $class
     *
     * @throws ReflectionException
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
     * Converts an inferred field into its `OA\Property`: nested-resource references resolve to
     * a `$ref` here (array-wrapped for `::collection()` values) — recursion into the nested
     * resource's own schema is cycle-guarded by the component registry.
     *
     * @throws ReflectionException
     */
    private function propertyFromInferredField(InferredResourceField $field): OA\Property
    {
        if ($field->resourceClass !== null) {
            $ref = $this->buildRef($field->resourceClass);

            if ($field->isCollection) {
                return new OA\Property([
                    'property' => $field->name,
                    'type' => 'array',
                    'items' => new OA\Items(['ref' => $ref]),
                ]);
            }

            return FieldReferenceProperty::build($field->name, description: null, ref: $ref);
        }

        assert($field->property !== null);

        return $field->property;
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
