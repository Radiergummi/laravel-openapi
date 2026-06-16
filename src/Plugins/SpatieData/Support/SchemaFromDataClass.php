<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData\Support;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Http\UploadedFile;
use OpenApi\Annotations as OA;
use Override;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Attributes\Deprecated as DeprecatedAttribute;
use Radiergummi\OpenApi\Attributes\Description as DescriptionAttribute;
use Radiergummi\OpenApi\Attributes\Discriminator as DiscriminatorAttribute;
use Radiergummi\OpenApi\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Attributes\Summary as SummaryAttribute;
use Radiergummi\OpenApi\Support\Extraction\FakerExampleSynthesiser;
use Radiergummi\OpenApi\Support\Extraction\FieldDescriptor;
use Radiergummi\OpenApi\Support\Extraction\RequestBodyExtractor;
use Radiergummi\OpenApi\Support\Extraction\ValidationRulesToSchema;
use Radiergummi\OpenApi\Support\Generator\ComponentReference;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\ExplicitClassSchema;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\Generator\NullableSchema;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;
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
use Symfony\Component\TypeInfo\TypeContext\TypeContext;
use Symfony\Component\TypeInfo\TypeContext\TypeContextFactory;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Throwable;

use function array_any;
use function array_filter;
use function array_key_exists;
use function array_values;
use function assert;
use function is_a;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function Radiergummi\OpenApi\is_defined;
use function sprintf;

/**
 * Builds an {@see OA\Schema} (type: object) from a {@see Data} subclass by reflecting its public
 * properties. `#[Computed]` properties are skipped; `Optional` in the union type removes the field
 * from `required`; nested Data classes are registered as components and referenced via `$ref`.
 */
#[Scoped]
final class SchemaFromDataClass implements FilePropertyChecker
{
    /** @var array<class-string<Data>, bool> */
    private array $filePropertiesCache = [];

    public function __construct(
        private readonly JsonSchemaFromType $schemaFromType,
        private readonly TypeResolver $typeResolver,
        private readonly ComponentSchemaRegistry $registry,
        private readonly DataSyntheticPayloadBuilder $payloadBuilder,
        private readonly ValidationRulesToSchema $rulesToSchema,
        private readonly DataConfig $dataConfig,
        private readonly LoggerInterface $logger,
        private readonly FakerExampleSynthesiser $synthesiser,
        private readonly ExplicitClassSchema $explicitSchema,
    ) {}

    /**
     * @param class-string<Data> $dataClass
     *
     * @throws ReflectionException
     * @throws RuntimeException
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
     * @throws RuntimeException
     * @throws UnsupportedException
     */
    private function buildSchema(string $dataClass): OA\Schema
    {
        $reflection = new ReflectionClass($dataClass);

        // #[RawSchema] replaces the inferred body wholesale; field-level attributes are ignored.
        if (($rawSchema = $this->explicitSchema->read($reflection)) !== null) {
            return $this->explicitSchema->toSchema($rawSchema, $reflection);
        }

        $title = $this->readClassAttributeValue($reflection, SummaryAttribute::class);
        $description = $this->readClassAttributeValue($reflection, DescriptionAttribute::class);

        // Discriminator path: emit oneOf + discriminator instead of a flat object schema.
        $discriminatorAttrs = $reflection->getAttributes(DiscriminatorAttribute::class);

        if ($discriminatorAttrs !== []) {
            /** @var DiscriminatorAttribute $discriminator */
            $discriminator = $discriminatorAttrs[0]->newInstance();
            $schema = $this->buildDiscriminatorSchema($discriminator);

            if ($title !== null) {
                $schema->title = $title;
            }

            if ($description !== null) {
                $schema->description = $description;
            }

            return $schema;
        }

        $contexts = $this->buildPropertyContexts($reflection, $dataClass);

        /** @var list<OA\Property> $properties */
        $properties = [];

        /** @var list<string> $required */
        $required = [];

        foreach ($contexts as $context) {
            $rawType = $context->reflection->getType();

            if ($rawType === null) {
                $properties[] = new OA\Property([
                    'property' => $context->wireName,
                    'description' => sprintf('Untyped property %s — schema unknown.', $context->wireName),
                ]);

                continue;
            }

            try {
                // Use the property reflector (not ReflectionNamedType) so `@var` generics are read.
                $type = $this->typeResolver->resolve($context->reflection, $this->typeContextFor($context->reflection));
            } catch (UnsupportedException) {
                $properties[] = new OA\Property([
                    'property' => $context->wireName,
                    'description' => sprintf('Type resolution failed for %s.', $context->wireName),
                ]);

                continue;
            }

            $hasOptional = $this->containsOptional($type);

            $effectiveType = $hasOptional ? $this->stripOptional($type) : $type;

            // DataCollection erases the value type; #[DataCollectionOf] is the only way to recover the item class.
            $dataCollectionSchema = $this->resolveDataCollectionSchema($context->reflection, $effectiveType);

            $schema = $dataCollectionSchema ?? $this->resolvePropertySchema($effectiveType, $context->wireName);
            $oaProperty = $this->schemaToProperty($context->wireName, $schema);

            if ($this->isPropertyDeprecated($context->reflection, $context->ctorParam)) {
                $oaProperty->deprecated = true;
            }

            $properties[] = $oaProperty;

            $hasDefault = $context->ctorParam !== null && $context->ctorParam->isOptional();

            if (!$hasDefault && !$hasOptional) {
                $required[] = $context->wireName;
            }
        }

        [$properties, $required] = $this->applyValidationRules($dataClass, $properties, $required);

        // Field attribute overrides applied last so authoring annotations win.
        $propsByWire = [];

        foreach ($properties as $prop) {
            $propsByWire[$prop->property] = $prop;
        }

        foreach ($contexts as $context) {
            if (isset($propsByWire[$context->wireName])) {
                $this->applyPropertyAttribute($context->reflection, $propsByWire[$context->wireName]);
            }
        }

        // Conditional fields stay in properties but must not appear in required[].
        foreach ($contexts as $context) {
            $attrs = $context->reflection->getAttributes(FieldAttribute::class, ReflectionAttribute::IS_INSTANCEOF);

            if ($attrs !== [] && $attrs[0]->newInstance()->conditional) {
                $wireName = $context->wireName;
                $required = array_values(
                    array_filter($required, static fn(string $name): bool => $name !== $wireName),
                );
            }
        }

        // Faker example synthesis runs last; authored sources always win.
        foreach ($propsByWire as $wireName => $property) {
            $this->synthesiseExample($wireName, $property);
        }

        $schemaProps = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schemaProps['required'] = $required;
        }

        if ($title !== null) {
            $schemaProps['title'] = $title;
        }

        if ($description !== null) {
            $schemaProps['description'] = $description;
        }

        return new OA\Schema($schemaProps);
    }

    /**
     * @param ReflectionClass<Data>                               $reflection
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

    /**
     * Each variant in the mapping is registered as its own component schema.
     *
     * @throws ReflectionException
     * @throws RuntimeException
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
     * Builds one {@see PropertyContext} per public, non-computed property of the Data class,
     * pairing each property with its constructor parameter and resolved wire name.
     *
     * @param ReflectionClass<Data> $reflection
     * @param class-string<Data>    $dataClass
     *
     * @return list<PropertyContext>
     */
    private function buildPropertyContexts(ReflectionClass $reflection, string $dataClass): array
    {
        $constructor = $reflection->getConstructor();
        $ctorParams = [];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                $ctorParams[$param->getName()] = $param;
            }
        }

        $wireNamesByPhpName = $this->buildWireNameMap($dataClass);

        $contexts = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            // Computed properties are derived at runtime, not input fields.
            if (!empty($prop->getAttributes(Computed::class))) {
                continue;
            }

            $phpName = $prop->getName();

            $contexts[] = new PropertyContext(
                wireName: $wireNamesByPhpName[$phpName] ?? $phpName,
                reflection: $prop,
                ctorParam: $ctorParams[$phpName] ?? null,
            );
        }

        return $contexts;
    }

    /**
     * Returns a `php_name => wire_name` map via Spatie's `DataConfig` (resolves `#[MapInputName]`).
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
     * Without this context, symfony/type-info throws for `self`/`static`/`parent` typed properties.
     */
    private function typeContextFor(ReflectionProperty $property): ?TypeContext
    {
        return new TypeContextFactory()->createFromReflection($property);
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
     * Removes `Optional` from the union; unwraps single-member remainders. `Optional|null`
     * falls back to `string`.
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

    /**
     * Returns an `array<DataClass>` schema for `DataCollection` properties (via `#[DataCollectionOf]`),
     * or null when the property is not a `DataCollection`.
     *
     * @throws ReflectionException
     * @throws RuntimeException
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
            'items' => new OA\Schema(['ref' => ComponentReference::pointer($itemKey)]),
        ]);
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

    /**
     * @throws ReflectionException
     * @throws RuntimeException
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
            return $this->resolveObjectSchema($type);
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
     * @throws RuntimeException
     * @throws UnsupportedException
     */
    private function resolveCollectionSchema(CollectionType $type, string $propertyName): OA\Schema
    {
        $valueType = $type->getCollectionValueType();

        // Pure string key → map (`additionalProperties`). int|string union key is the "unknown"
        // case (bare `array` / `array<array-key, T>`), not an asserted map.
        if (!$type->isList() && $this->isStringKey($type->getCollectionKeyType())) {
            return new OA\Schema([
                'type' => 'object',
                'additionalProperties' => $this->mapValueSchema($valueType, $propertyName),
            ]);
        }

        if (
            $valueType instanceof ObjectType
            && is_a($valueType->getClassName(), Data::class, allow_string: true)
        ) {
            /** @var class-string<Data> $itemClass */
            $itemClass = $valueType->getClassName();
            $itemKey = $this->build($itemClass);

            return new OA\Schema([
                'type' => 'array',
                'items' => new OA\Schema(['ref' => ComponentReference::pointer($itemKey)]),
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
     * True when the collection key is a pure `string` (a map), not int|string (bare `array`).
     */
    private function isStringKey(Type $keyType): bool
    {
        return $keyType instanceof BuiltinType
            && $keyType->isIdentifiedBy(TypeIdentifier::STRING);
    }

    /**
     * Schema for a map's value type; degrades to `additionalProperties: true` for opaque `mixed`.
     *
     * @return OA\Schema|true permissive `additionalProperties` for an opaque `mixed` value
     *
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnsupportedException
     */
    private function mapValueSchema(Type $valueType, string $propertyName): OA\Schema|bool
    {
        if (
            $valueType instanceof BuiltinType
            && $valueType->isIdentifiedBy(TypeIdentifier::MIXED)
        ) {
            return true;
        }

        return $this->resolvePropertySchema($valueType, $propertyName);
    }

    /**
     * @param ObjectType<class-string> $type
     *
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnsupportedException
     */
    private function resolveObjectSchema(ObjectType $type): OA\Schema
    {
        $className = $type->getClassName();

        if (is_a($className, UploadedFile::class, allow_string: true)) {
            return new OA\Schema([
                'type' => 'string',
                'format' => 'binary',
            ]);
        }

        if (is_a($className, Data::class, allow_string: true)) {
            /** @var class-string<Data> $className */
            $key = $this->build($className);

            return new OA\Schema(['ref' => ComponentReference::pointer($key)]);
        }

        return $this->schemaFromType->fromType($type);
    }

    /**
     * Copies non-UNDEFINED JSON-Schema/OAS fields from an {@see OA\Schema} into an {@see OA\Property}.
     * swagger-php internals (`_context`, `_unmerged`, etc.) are excluded.
     */
    private function schemaToProperty(string $name, OA\Schema $schema): OA\Property
    {
        $props = ['property' => $name];

        static $allowlist = [
            'type',
            'format',
            'ref',
            'oneOf',
            'allOf',
            'anyOf',
            'items',
            'enum',
            'description',
            'example',
            'nullable',
            'minimum',
            'maximum',
            'exclusiveMinimum',
            'exclusiveMaximum',
            'minLength',
            'maxLength',
            'pattern',
            'minItems',
            'maxItems',
            'uniqueItems',
            'multipleOf',
            'properties',
            'additionalProperties',
            'required',
            'deprecated',
            'readOnly',
            'writeOnly',
            'default',
            'title',
        ];

        foreach ($allowlist as $field) {
            $value = $schema->{$field};

            if (is_defined($value)) {
                $props[$field] = $value;
            }
        }

        return new OA\Property($props);
    }

    /**
     * Checks `#[Deprecated]` (on property or promoted ctor param) or a `@deprecated` PHPDoc tag.
     * PHP 8.4's native `#[\Deprecated]` is not consulted: it does not support `TARGET_PROPERTY`.
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

    /**
     * Merges validation-rule constraints onto the type-derived properties without overwriting them.
     * A `sometimes` rule (`required === false`) is the only way rules can demote a required field.
     * Logs at warning and returns inputs unchanged on any exception.
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

        if ($cached === null) {
            try {
                $payload = $this->payloadBuilder->build($dataClass);
                $raw = $dataClass::getValidationRules($payload);
                $normalised = $this->rulesToSchema->normaliseIndexedPaths($raw);
                $processed = $this->rulesToSchema->process($normalised, sourceClass: $dataClass);
                $cached = $processed['fields'];
                $this->registry->setCompiledFields($dataClass, $cached);
            } catch (Throwable $exception) {
                $this->logger->warning(
                    sprintf(
                        'SchemaFromDataClass: Skipping validation rule extraction for %s: %s',
                        $dataClass,
                        $exception->getMessage(),
                    ),
                );
                $this->registry->setCompiledFields($dataClass, []);

                return [$properties, $required];
            }
        }

        /** @var array<string, FieldDescriptor> $fieldMap */
        $fieldMap = $cached;

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

            if ($descriptor->required === false) {
                $required = array_values(
                    array_filter(
                        $required,
                        static fn(string $name): bool => $name !== $fieldName,
                    ),
                );
            }
        }

        return [$properties, $required];
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

    /**
     * Lowest-priority example fallback; skips $ref/composed schemas and array/object types.
     */
    private function synthesiseExample(string $wireName, OA\Property $property): void
    {
        if (is_defined($property->example)) {
            return;
        }

        if (
            is_defined($property->ref)
            || is_defined($property->oneOf)
            || is_defined($property->allOf)
            || is_defined($property->anyOf)
        ) {
            return;
        }

        // `Generator::UNDEFINED` is a string sentinel; reject it or it leaks into the descriptor.
        $type = (is_string($property->type) && is_defined($property->type))
            ? $property->type
            : null;

        if ($type === 'array' || $type === 'object') {
            return;
        }

        $descriptor = new FieldDescriptor();
        $descriptor->type = $type;
        $descriptor->format = (is_string($property->format) && is_defined($property->format))
            ? $property->format
            : null;

        if (is_array($property->enum)) {
            /** @var list<float|int|string> $enum */
            $enum = array_values(
                array_filter(
                    $property->enum,
                    static fn(mixed $value): bool => is_int($value) || is_float($value) || is_string($value),
                ),
            );
            $descriptor->enum = $enum;
        }

        if (is_int($property->minimum) || is_float($property->minimum)) {
            $descriptor->minimum = $property->minimum;
        }

        if (is_int($property->maximum) || is_float($property->maximum)) {
            $descriptor->maximum = $property->maximum;
        }

        $synthesised = $this->synthesiser->synthesise($wireName, $descriptor);

        if ($synthesised !== null) {
            $property->example = $synthesised;
        }
    }

    /**
     * True when any public property of `$dataClass` (or a transitively nested Data class) is typed
     * as {@see UploadedFile}. Used by {@see RequestBodyExtractor} to switch to `multipart/form-data`.
     *
     * @param class-string<Data> $dataClass
     *
     * @throws ReflectionException
     */
    #[Override]
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
                $type = $this->typeResolver->resolve($rawType, $this->typeContextFor($prop));
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
            && is_a($type->getClassName(), UploadedFile::class, allow_string: true);
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
}
