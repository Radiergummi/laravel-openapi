<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData;

use Illuminate\Http\UploadedFile;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Core\Attributes\Deprecated as DeprecatedAttribute;
use Radiergummi\OpenApi\Core\Attributes\Discriminator as DiscriminatorAttribute;
use Radiergummi\OpenApi\Core\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Core\Extractors\FieldDescriptor;
use Radiergummi\OpenApi\Core\Extractors\RequestBodyExtractor;
use Radiergummi\OpenApi\Core\Extractors\ValidationRulesToSchema;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Core\Generator\NullableSchema;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;
use ReflectionProperty;
use Spatie\LaravelData\Attributes\Computed;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\DataConfig;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\Type\UnionType;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Throwable;

use function array_any;
use function array_key_exists;
use function assert;
use function is_a;
use function preg_match;
use function sprintf;

/**
 * Builds an {@see OA\Schema} (type: object) from a {@see Data} subclass by reflecting its public
 * properties.
 *
 * - `#[Computed]` properties are skipped (derived, not input).
 * - `Spatie\LaravelData\Optional` in the union type → property excluded from `required`.
 * - A property is required iff it has no default value AND `Optional` is not in its union.
 * - Nested Data classes are registered as components and referenced via `$ref`.
 */
final class SchemaFromDataClass implements FilePropertyChecker
{
    /**
     * Per-class memoization cache for {@see hasFileProperties()}.
     * Keyed by Data-class FQCN; stored as local instance state so it stays
     * within the SpatieData plugin without touching Core registries.
     *
     * @var array<class-string<Data>, bool>
     */
    private array $filePropertiesCache = [];

    public function __construct(
        private readonly JsonSchemaFromType $schemaFromType,
        private readonly TypeResolver $typeResolver,
        private readonly ComponentSchemaRegistry $registry,
        private readonly DataSyntheticPayloadBuilder $payloadBuilder,
        private readonly ValidationRulesToSchema $rulesToSchema,
        private readonly DataConfig $dataConfig,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Registers the Data class as a component schema and returns the component key.
     *
     * No-op when already registered — returns the existing key.
     *
     * @param class-string<Data> $dataClass
     *
     * @throws ReflectionException
     * @throws UnsupportedException
     */
    public function build(string $dataClass): string
    {
        return $this->registry->buildOnce($dataClass, fn(): OA\Schema => $this->buildSchema($dataClass));
    }

    /**
     * @param class-string<Data> $dataClass
     *
     * @throws ReflectionException
     * @throws UnsupportedException
     */
    private function buildSchema(string $dataClass): OA\Schema
    {
        $reflection = new ReflectionClass($dataClass);

        // Discriminator path: emit oneOf + discriminator instead of a flat object schema.
        $discriminatorAttrs = $reflection->getAttributes(DiscriminatorAttribute::class);

        if ($discriminatorAttrs !== []) {
            /** @var DiscriminatorAttribute $discriminator */
            $discriminator = $discriminatorAttrs[0]->newInstance();

            return $this->buildDiscriminatorSchema($discriminator);
        }

        $constructor = $reflection->getConstructor();

        $ctorParams = [];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                $ctorParams[$param->getName()] = $param;
            }
        }

        // Wire-name resolution: Spatie DataConfig exposes the same input mapping (literal string or
        // NameMapper class) that Data::from() / Data::getValidationRules() use, so the schema's
        // property keys, the required[] list, and the rules-derived field map all line up on a
        // single set of names.
        $wireNamesByPhpName = $this->buildWireNameMap($dataClass);

        /** @var list<OA\Property> $properties */
        $properties = [];

        /** @var list<string> $required */
        $required = [];

        /** @var array<string, ReflectionProperty> $reflectionPropsByWireName */
        $reflectionPropsByWireName = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            // Skip Computed properties — they are derived, not input.
            if (!empty($prop->getAttributes(Computed::class))) {
                continue;
            }

            $phpName = $prop->getName();
            $name = $wireNamesByPhpName[$phpName] ?? $phpName;
            $rawType = $prop->getType();
            $reflectionPropsByWireName[$name] = $prop;

            if ($rawType === null) {
                $properties[] = new OA\Property([
                    'property' => $name,
                    'description' => sprintf('Untyped property %s — schema unknown.', $name),
                ]);

                continue;
            }

            try {
                $type = $this->typeResolver->resolve($rawType);
            } catch (UnsupportedException) {
                $properties[] = new OA\Property([
                    'property' => $name,
                    'description' => sprintf('Type resolution failed for %s.', $name),
                ]);

                continue;
            }

            $hasOptional = $this->containsOptional($type);

            // Strip Optional from the union before schema generation.
            $effectiveType = $hasOptional ? $this->stripOptional($type) : $type;

            // DataCollection short-circuit — the property's #[DataCollectionOf] attribute names the
            // item Data class; type-info has no other way to recover it because DataCollection
            // erases the value type.
            $dataCollectionSchema = $this->resolveDataCollectionSchema($prop, $effectiveType);

            $schema = $dataCollectionSchema ?? $this->resolvePropertySchema($effectiveType, $name);
            $oaProperty = $this->schemaToProperty($name, $schema);

            // PHP name keys constructor parameters; the mapping is only on the wire side.
            $ctorParam = $ctorParams[$phpName] ?? null;

            if ($this->isPropertyDeprecated($prop, $ctorParam)) {
                $oaProperty->deprecated = true;
            }

            $properties[] = $oaProperty;

            $hasDefault = $ctorParam !== null && $ctorParam->isOptional();

            if (!$hasDefault && !$hasOptional) {
                $required[] = $name;
            }
        }

        // Pass 2: merge validation-rule constraints onto the type-derived properties.
        [$properties, $required] = $this->applyValidationRules($dataClass, $properties, $required);

        // Pass 3: apply scoped field attribute overrides last so authoring annotations win.
        foreach ($properties as $oaProperty) {
            $reflectionProp = $reflectionPropsByWireName[$oaProperty->property] ?? null;

            if ($reflectionProp !== null) {
                $this->applyPropertyAttribute($reflectionProp, $oaProperty);
            }
        }

        $schemaProps = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schemaProps['required'] = $required;
        }

        return new OA\Schema($schemaProps);
    }

    /**
     * Builds a `oneOf` + `discriminator` schema for a polymorphic base class.
     *
     * Each variant class in the mapping is registered as its own component schema (via the normal
     * `build()` path). The base class schema becomes a `oneOf` listing each variant's `$ref` plus a
     * `discriminator` object.
     *
     * @throws ReflectionException
     * @throws UnsupportedException
     */
    private function buildDiscriminatorSchema(DiscriminatorAttribute $discriminator): OA\Schema
    {
        $variantKeys = [];

        foreach ($discriminator->mapping as $variantClass) {
            assert(is_a($variantClass, Data::class, allow_string: true));
            $variantKeys[$variantClass] = $this->build($variantClass);
        }

        return $discriminator->assemble($variantKeys);
    }

    /**
     * @throws ReflectionException
     * @throws UnsupportedException
     */
    private function resolvePropertySchema(Type $type, string $propertyName): OA\Schema
    {
        if ($type instanceof NullableType) {
            return NullableSchema::wrap($this->resolvePropertySchema($type->getWrappedType(), $propertyName));
        }

        if ($type instanceof CollectionType) {
            return $this->resolveCollectionSchema($type, $propertyName);
        }

        if ($type instanceof ObjectType) {
            return $this->resolveObjectSchema($type, $propertyName);
        }

        if ($type instanceof UnionType) {
            return new OA\Schema([
                'oneOf' => array_map(
                    fn(Type $member): OA\Schema => $this->resolvePropertySchema($member, $propertyName),
                    $type->getTypes(),
                ),
            ]);
        }

        return $this->schemaFromType->fromType($type);
    }

    /**
     * @param CollectionType<BuiltinType<TypeIdentifier::ARRAY>|BuiltinType<TypeIdentifier::ITERABLE>|ObjectType<class-string>> $type
     *
     * @throws ReflectionException
     * @throws UnsupportedException
     */
    private function resolveCollectionSchema(CollectionType $type, string $propertyName): OA\Schema
    {
        $valueType = $type->getCollectionValueType();

        if (
            $valueType instanceof ObjectType
            && is_a($valueType->getClassName(), Data::class, allow_string: true)
        ) {
            /** @var class-string<Data> $itemClass */
            $itemClass = $valueType->getClassName();
            $itemKey = $this->build($itemClass);

            return new OA\Schema([
                'type' => 'array',
                'items' => new OA\Schema(['ref' => "#/components/schemas/{$itemKey}"]),
            ]);
        }

        if (
            $valueType instanceof BuiltinType
            && $valueType->getTypeIdentifier() !== TypeIdentifier::MIXED
            && $valueType->getTypeIdentifier() !== TypeIdentifier::NULL
        ) {
            return new OA\Schema([
                'type' => 'array',
                'items' => $this->schemaFromType->fromType($valueType),
            ]);
        }

        // swagger-php requires items even for untyped arrays.
        return new OA\Schema([
            'type' => 'array',
            'items' => new OA\Items([]),
            'description' => sprintf(
                'Array property `%s` — element type is opaque (no @var annotation with a concrete type).',
                $propertyName,
            ),
        ]);
    }

    /**
     * @param ObjectType<class-string> $type
     *
     * @throws ReflectionException
     * @throws UnsupportedException
     */
    private function resolveObjectSchema(ObjectType $type, string $propertyName): OA\Schema
    {
        $className = $type->getClassName();

        if (is_a($className, UploadedFile::class, allow_string: true)) {
            return new OA\Schema([
                'type' => 'string',
                'format' => 'binary',
                'description' => sprintf(
                    'File upload property `%s` — multipart/form-data bodies are not yet fully modelled.',
                    $propertyName,
                ),
            ]);
        }

        if (is_a($className, Data::class, allow_string: true)) {
            /** @var class-string<Data> $className */
            $key = $this->build($className);

            return new OA\Schema(['ref' => "#/components/schemas/{$key}"]);
        }

        return $this->schemaFromType->fromType($type);
    }

    /**
     * Returns true when any public property of `$dataClass` (or any nested Data class it
     * transitively references) is typed as {@see UploadedFile}.
     *
     * Used by {@see RequestBodyExtractor} to switch the request media type to
     * `multipart/form-data`. Cached by class for the lifetime of the registry to keep deep nested
     * checks cheap.
     *
     * @param class-string<Data> $dataClass
     *
     * @throws ReflectionException
     */
    public function hasFileProperties(string $dataClass): bool
    {
        return $this->filePropertiesCache[$dataClass]
            ??= $this->detectFileProperties($dataClass, []);
    }

    /**
     * @param class-string<Data>              $dataClass
     * @param array<class-string<Data>, true> $visited   Recursion guard.
     *
     * @throws ReflectionException
     */
    private function detectFileProperties(string $dataClass, array $visited): bool
    {
        if (isset($visited[$dataClass])) {
            return false;
        }

        $visited[$dataClass] = true;
        $reflection = new ReflectionClass($dataClass);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $rawType = $prop->getType();

            if ($rawType === null) {
                continue;
            }

            try {
                $type = $this->typeResolver->resolve($rawType);
            } catch (UnsupportedException) {
                continue;
            }

            if ($this->typeReferencesUploadedFile($type)) {
                return true;
            }

            if (array_any(
                $this->collectNestedDataClasses($type, $prop),
                fn(string $nested): bool => $this->detectFileProperties($nested, $visited),
            )) {
                return true;
            }
        }

        return false;
    }

    private function typeReferencesUploadedFile(Type $type): bool
    {
        if ($type instanceof NullableType) {
            return $this->typeReferencesUploadedFile($type->getWrappedType());
        }

        if ($type instanceof UnionType) {
            return array_any(
                $type->getTypes(),
                fn(Type $member): bool => $this->typeReferencesUploadedFile($member),
            );
        }

        if ($type instanceof CollectionType) {
            return $this->typeReferencesUploadedFile($type->getCollectionValueType());
        }

        return
            $type instanceof ObjectType
            && is_a($type->getClassName(), UploadedFile::class, allow_string: true)
        ;
    }

    /**
     * @return list<class-string<Data>>
     */
    private function collectNestedDataClasses(Type $type, ReflectionProperty $prop): array
    {
        if ($type instanceof NullableType) {
            return $this->collectNestedDataClasses($type->getWrappedType(), $prop);
        }

        if ($type instanceof UnionType) {
            $result = [];

            foreach ($type->getTypes() as $member) {
                foreach ($this->collectNestedDataClasses($member, $prop) as $className) {
                    $result[] = $className;
                }
            }

            return $result;
        }

        if ($type instanceof CollectionType) {
            return $this->collectNestedDataClasses($type->getCollectionValueType(), $prop);
        }

        if ($type instanceof ObjectType) {
            $className = $type->getClassName();

            if (is_a($className, Data::class, allow_string: true)) {
                /** @var class-string<Data> $className */
                return [$className];
            }

            if (is_a($className, DataCollection::class, allow_string: true)) {
                $itemClass = $this->readDataCollectionItemClass($prop);

                return $itemClass !== null ? [$itemClass] : [];
            }
        }

        return [];
    }

    /**
     * Returns an `array<DataClass>` schema for properties typed as {@see DataCollection} with a
     * `#[DataCollectionOf]` attribute. Returns null when the property is not a `DataCollection`;
     * the caller falls back to standard property-schema resolution.
     *
     * @throws ReflectionException
     * @throws UnsupportedException
     */
    private function resolveDataCollectionSchema(ReflectionProperty $prop, Type $type): ?OA\Schema
    {
        if (!$this->typeIsDataCollection($type)) {
            return null;
        }

        $itemClass = $this->readDataCollectionItemClass($prop);

        if ($itemClass === null) {
            return new OA\Schema([
                'type' => 'array',
                'items' => new OA\Items([]),
                'description' => sprintf(
                    'DataCollection property `%s` — item class is opaque (missing #[DataCollectionOf]).',
                    $prop->getName(),
                ),
            ]);
        }

        $itemKey = $this->build($itemClass);

        return new OA\Schema([
            'type' => 'array',
            'items' => new OA\Schema(['ref' => "#/components/schemas/{$itemKey}"]),
        ]);
    }

    /**
     * @return null|class-string<Data>
     */
    private function readDataCollectionItemClass(ReflectionProperty $prop): ?string
    {
        $attributes = $prop->getAttributes(DataCollectionOf::class);

        if ($attributes === []) {
            return null;
        }

        $itemClass = $attributes[0]->newInstance()->class;

        if (!is_a($itemClass, Data::class, allow_string: true)) {
            return null;
        }

        /** @var class-string<Data> $itemClass */
        return $itemClass;
    }

    private function typeIsDataCollection(Type $type): bool
    {
        if ($type instanceof NullableType) {
            return $this->typeIsDataCollection($type->getWrappedType());
        }

        if ($type instanceof UnionType) {
            return array_any(
                $type->getTypes(),
                fn(Type $member): bool => $this->typeIsDataCollection($member),
            );
        }

        return $type instanceof ObjectType
            && is_a($type->getClassName(), DataCollection::class, allow_string: true);
    }

    /**
     * Converts an {@see OA\Schema} into an {@see OA\Property} by copying non-UNDEFINED JSON-Schema
     * fields. Only JSON-Schema / OAS fields are transferred; swagger-php internals (e.g. `_context`,
     * `schema`, `_unmerged`) are intentionally excluded.
     */
    private function schemaToProperty(string $name, OA\Schema $schema): OA\Property
    {
        $undefined = Generator::UNDEFINED;
        $props = ['property' => $name];

        static $allowlist = [
            'type', 'format', 'ref', 'oneOf', 'allOf', 'anyOf', 'items', 'enum',
            'description', 'example', 'nullable', 'minimum', 'maximum',
            'exclusiveMinimum', 'exclusiveMaximum', 'minLength', 'maxLength',
            'pattern', 'minItems', 'maxItems', 'uniqueItems', 'multipleOf',
            'properties', 'additionalProperties', 'required', 'deprecated',
            'readOnly', 'writeOnly', 'default', 'title',
        ];

        foreach ($allowlist as $field) {
            $value = $schema->{$field};

            if ($value !== $undefined) {
                $props[$field] = $value;
            }
        }

        return new OA\Property($props);
    }

    /**
     * Returns a `php_name => wire_name` map for the Data class's input mapping.
     *
     * Spatie's `DataConfig` already resolves `#[MapInputName]` (whether literal-string or
     * NameMapper-class form) at metadata-collection time; reading from it avoids re-implementing
     * the resolution logic and ensures schema names line up with the same names that
     * `Data::from()` / `Data::getValidationRules()` consume.
     *
     * @param class-string<Data> $dataClass
     *
     * @return array<string, string>
     */
    private function buildWireNameMap(string $dataClass): array
    {
        $map = [];

        foreach ($this->dataConfig->getDataClass($dataClass)->properties as $property) {
            $map[$property->name] = $property->inputMappedName ?? $property->name;
        }

        return $map;
    }

    /**
     * Merges validation-rule constraints onto the type-derived properties.
     *
     * Merge rules:
     * - Type-derived `enum` (BackedEnum) takes precedence over rule-derived enum.
     * - The PHP-type pass is authoritative for `$required` membership. Rules can only REMOVE a
     *   field (descriptor `required === false`: `sometimes`) — they cannot add. `null` means the
     *   rules said nothing about required and the structural decision stands.
     * - Scoped field attributes (`#[RequestField]` / `#[ResponseField]`) are applied in a separate
     *   pass after this one, so authoring annotations win.
     *
     * On any exception the method logs at debug and returns inputs unchanged.
     *
     * @param class-string<Data> $dataClass
     * @param list<OA\Property>  $properties
     * @param list<string>       $required
     *
     * @return array{0: list<OA\Property>, 1: list<string>}
     */
    private function applyValidationRules(string $dataClass, array $properties, array $required): array
    {
        $cached = $this->registry->compiledFields($dataClass);
        $cachedItems = $this->registry->compiledItemsFields($dataClass);

        if ($cached === null) {
            try {
                $payload = $this->payloadBuilder->build($dataClass);
                $raw = $dataClass::getValidationRules($payload);
                $normalised = $this->rulesToSchema->normaliseIndexedPaths($raw);
                $processed = $this->rulesToSchema->process($normalised, sourceClass: $dataClass);
                $cached = $processed['fields'];
                $cachedItems = $processed['itemsFields'];
                $this->registry->setCompiledFields($dataClass, $cached);
                $this->registry->setCompiledItemsFields($dataClass, $cachedItems);
            } catch (Throwable $exception) {
                $this->logger->warning(
                    sprintf(
                        'SchemaFromDataClass: Skipping validation rule extraction for %s: %s',
                        $dataClass,
                        $exception->getMessage(),
                    ),
                );
                $this->registry->setCompiledFields($dataClass, []);
                $this->registry->setCompiledItemsFields($dataClass, []);

                return [$properties, $required];
            }
        }

        /** @var array<string, FieldDescriptor> $fieldMap */
        $fieldMap = $cached;

        /** @var array<string, FieldDescriptor> $itemsMap */
        $itemsMap = $cachedItems ?? [];

        /** @var array<string, OA\Property> $propsByName */
        $propsByName = [];

        foreach ($properties as $prop) {
            $propsByName[$prop->property] = $prop;
        }

        foreach ($fieldMap as $fieldName => $descriptor) {
            if (!array_key_exists($fieldName, $propsByName)) {
                continue;
            }

            $descriptor->applyTo($propsByName[$fieldName], overwrite: false);

            // Only an explicit `sometimes` rule (descriptor->required === false) demotes a
            // structurally required field; `null` means rules said nothing and the PHP-type pass's
            // decision stands.
            if ($descriptor->required === false) {
                $required = array_values(
                    array_filter(
                        $required,
                        static fn(string $name): bool => $name !== $fieldName,
                    ),
                );
            }
        }

        foreach ($itemsMap as $fieldName => $itemsDescriptor) {
            $prop = $propsByName[$fieldName] ?? null;

            if ($prop === null) {
                continue;
            }

            $items = new OA\Items([]);
            $itemsDescriptor->applyTo($items);

            // When the property is expressed as oneOf (nullable array wrapped by NullableSchema),
            // items must go onto the type:'array' inner schema — not on the outer oneOf wrapper,
            // which would trigger swagger-php's "OA\Items() parent type must be array" check.
            if (is_array($prop->oneOf)) {
                foreach ($prop->oneOf as $branch) {
                    if ($branch instanceof OA\Schema && $branch->type === 'array') {
                        $branch->items = $items;

                        break;
                    }
                }
            } else {
                $prop->items = $items;
            }
        }

        return [$properties, $required];
    }

    /**
     * Detects whether the property is deprecated.
     *
     * Three signals are honoured, in order of authoring convenience:
     *
     * 1. The package's own `#[Deprecated]` attribute on the property or its promoted constructor
     *    parameter — the symmetric authoring path.
     * 2. The PHPDoc `@deprecated` tag on the property — works on every Data class with a
     *    PHPDoc block, and is what most IDEs surface in completion.
     *
     * PHP 8.4's native `#[\Deprecated]` is not consulted here because it does not support
     * `TARGET_PROPERTY` or `TARGET_PARAMETER`.
     */
    private function isPropertyDeprecated(ReflectionProperty $prop, ?ReflectionParameter $ctorParam): bool
    {
        if ($prop->getAttributes(DeprecatedAttribute::class) !== []) {
            return true;
        }

        if ($ctorParam !== null && $ctorParam->getAttributes(DeprecatedAttribute::class) !== []) {
            return true;
        }

        $docComment = $prop->getDocComment();

        return $docComment !== false && preg_match('/@deprecated\b/i', $docComment) === 1;
    }

    private function applyPropertyAttribute(ReflectionProperty $prop, OA\Property $property): void
    {
        $attributes = $prop->getAttributes(
            FieldAttribute::class,
            ReflectionAttribute::IS_INSTANCEOF,
        );

        if ($attributes === []) {
            return;
        }

        $attributes[0]->newInstance()->descriptor()->applyTo($property);
    }

    private function containsOptional(Type $type): bool
    {
        if (!$type instanceof UnionType) {
            return false;
        }

        return array_any(
            $type->getTypes(),
            static fn(Type $member): bool
                => $member instanceof ObjectType && $member->getClassName() === Optional::class,
        );
    }

    /**
     * Returns a new type with `Optional` removed from the union.
     *
     * If after removal only one member remains, that member is returned directly. If the sole
     * remaining type is the null builtin, a `NullableType` wrapping `string` is returned as a
     * fallback (edge case: `Optional|null` only).
     */
    private function stripOptional(Type $type): Type
    {
        if (!$type instanceof UnionType) {
            return $type;
        }

        $remaining = array_values(
            array_filter(
                $type->getTypes(),
                static fn(Type $member): bool
                    => !(
                        $member instanceof ObjectType
                    && $member->getClassName() === Optional::class
                    ),
            ),
        );

        return match (true) {
            count($remaining) === 0 => Type::builtin(TypeIdentifier::STRING),
            count($remaining) === 1 => $remaining[0],
            default => Type::union(...$remaining),
        };
    }
}
