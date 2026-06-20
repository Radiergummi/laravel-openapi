<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use BackedEnum;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use OpenApi\Annotations as OA;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Radiergummi\OpenApi\Support\Types\TypeNodeToSchema;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function class_exists;
use function enum_exists;
use function in_array;
use function is_a;
use function ltrim;
use function Radiergummi\OpenApi\copy_schema_fields;
use function Radiergummi\OpenApi\is_undefined;
use function str_replace;
use function str_starts_with;
use function strtok;
use function strtolower;
use function substr;
use function trim;
use function ucwords;

/**
 * Builds an OpenAPI schema for an Eloquent model from its metadata: cast keys, fillable fields,
 * appends, and docblock at-property names.
 *
 * @internal
 */
#[Scoped]
final class EloquentModelToSchema
{
    /**
     * Per-model metadata memo; null marks a non-instantiable model (warned once).
     *
     * @var array<class-string<Model>, null|array{
     *     reflection: ReflectionClass<Model>,
     *     casts: array<string, string>,
     *     propertyTags: array<string, PropertyTagValueNode>,
     *     appends: list<string>,
     *     fillable: list<string>,
     *     hidden: list<string>,
     *     visible: list<string>,
     *     timestamps: list<string>,
     * }>
     */
    private array $metadataCache = [];

    public function __construct(
        private readonly ComponentSchemaRegistry $registry,
        private readonly JsonSchemaFromType $jsonSchemaFromType,
        private readonly TypeNodeToSchema $typeNodeToSchema,
        private readonly TypeResolver $typeResolver,
        private readonly TypeNodeResolver $typeNodeResolver,
        private readonly DocBlockParser $docBlockParser,
        private readonly LoggerInterface $logger,
        private readonly ModelFactoryExampleReader $factoryExampleReader,
    ) {}

    /**
     * Schema for one model property by name (from `$casts`, `@property`/`@property-read`, or
     * appended accessor), or null when the model carries no metadata under that name.
     *
     * No `$hidden`/`$visible` filtering: those govern the model's own serialization, while a
     * caller resolving a name it found elsewhere has already decided the property is output.
     *
     * @param class-string<Model> $modelClass
     *
     * @throws ReflectionException
     */
    public function propertyFor(string $modelClass, string $propertyName): ?OA\Property
    {
        $metadata = $this->metadataFor($modelClass);

        if ($metadata === null) {
            return null;
        }

        $property = $this->buildPropertyFor($metadata, $propertyName);

        return $property === null
            ? null
            : $this->applyTagDescription($property, $metadata['propertyTags'][$propertyName] ?? null);
    }

    /**
     * Builds the property for a name without decorating it; the caller applies the docblock
     * description once to the single result, so every branch (cast, enum, timestamp, untyped
     * fallback) is covered by one chokepoint.
     *
     * @param array{
     *     reflection: ReflectionClass<Model>,
     *     casts: array<string, string>,
     *     propertyTags: array<string, PropertyTagValueNode>,
     *     appends: list<string>,
     *     fillable: list<string>,
     *     hidden: list<string>,
     *     visible: list<string>,
     *     timestamps: list<string>,
     * } $metadata
     *
     * @throws ReflectionException
     */
    private function buildPropertyFor(array $metadata, string $propertyName): ?OA\Property
    {
        $castString = $metadata['casts'][$propertyName] ?? null;
        $tag = $metadata['propertyTags'][$propertyName] ?? null;

        if ($castString !== null) {
            if (enum_exists($castString) && is_a($castString, BackedEnum::class, allow_string: true)) {
                return $this->propertyFromSchema(
                    $propertyName,
                    $this->jsonSchemaFromType->fromBackedEnumClass($castString),
                );
            }

            $property = $this->castToProperty($propertyName, $castString, $tag?->type);

            if ($property !== null) {
                return $property;
            }

            // Unrecognised cast (custom CastsAttributes): defer to the `@property` tag, or fall
            // back to an untyped property.
            if ($tag !== null) {
                $property = $this->propertyFromTag($propertyName, $tag->type, $metadata['reflection']);

                if ($property !== null) {
                    return $property;
                }
            }

            return new OA\Property(['property' => $propertyName]);
        }

        if ($tag !== null) {
            $property = $this->propertyFromTag($propertyName, $tag->type, $metadata['reflection']);

            if ($property !== null) {
                return $property;
            }
        }

        if (in_array($propertyName, $metadata['appends'], strict: true)) {
            $property = $this->propertyFromAccessor($metadata['reflection'], $propertyName);

            if ($property !== null) {
                return $property;
            }
        }

        // Timestamp columns carry no explicit metadata; type below cast/tag, above untyped fallback.
        if (in_array($propertyName, $metadata['timestamps'], strict: true)) {
            return $this->timestampProperty($propertyName);
        }

        // Name is known but type is not derivable: emit an untyped property.
        if (
            $tag !== null
            || in_array($propertyName, $metadata['appends'], strict: true)
            || in_array($propertyName, $metadata['fillable'], strict: true)
        ) {
            return new OA\Property(['property' => $propertyName]);
        }

        return null;
    }

    /**
     * Sets a `@property`/`@property-read` tag's trailing prose as the property description, unless
     * one is already present, the tag carries none, or the property is a bare `$ref` (its description
     * lives on the referenced component, not the reference). Lets an authored attribute or a
     * documented-enum case list pre-empt the free prose.
     */
    private function applyTagDescription(OA\Property $property, ?PropertyTagValueNode $tag): OA\Property
    {
        if ($tag === null) {
            return $property;
        }

        $description = trim($tag->description);

        if (
            $description !== ''
            && is_undefined($property->description)
            && is_undefined($property->ref)
        ) {
            $property->description = $description;
        }

        return $property;
    }

    /**
     * Gathers (and memoises) reflection-level metadata for a model class, or null when
     * the model is not instantiable. Non-instantiable models (abstract, etc.) would throw
     * from `new $modelClass()`; returning null lets callers degrade without aborting the run.
     *
     * @param class-string<Model> $modelClass
     *
     * @return null|array{
     *     reflection: ReflectionClass<Model>,
     *     casts: array<string, string>,
     *     propertyTags: array<string, PropertyTagValueNode>,
     *     appends: list<string>,
     *     fillable: list<string>,
     *     hidden: list<string>,
     *     visible: list<string>,
     *     timestamps: list<string>,
     * }
     *
     * @throws ReflectionException
     */
    private function metadataFor(string $modelClass): ?array
    {
        if (array_key_exists($modelClass, $this->metadataCache)) {
            return $this->metadataCache[$modelClass];
        }

        $reflection = new ReflectionClass($modelClass);

        if (!$reflection->isInstantiable()) {
            $this->logger->warning('EloquentModelToSchema: model is not instantiable, using empty fallback', [
                'model' => $modelClass,
            ]);

            return $this->metadataCache[$modelClass] = null;
        }

        $model = new $modelClass();

        $docComment = $reflection->getDocComment();

        /** @var array<string, PropertyTagValueNode> $propertyTags */
        $propertyTags = [];

        if ($docComment !== false) {
            $parsed = $this->docBlockParser->parse($docComment);

            foreach ($parsed->tagValues('@property') as $tag) {
                if ($tag instanceof PropertyTagValueNode) {
                    $propertyTags[ltrim($tag->propertyName, '$')] = $tag;
                }
            }

            foreach ($parsed->tagValues('@property-read') as $tag) {
                if ($tag instanceof PropertyTagValueNode) {
                    $propertyTags[ltrim($tag->propertyName, '$')] = $tag;
                }
            }
        }

        $timestamps = [];

        if ($model->usesTimestamps()) {
            foreach ([$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()] as $column) {
                if ($column !== null) {
                    $timestamps[] = $column;
                }
            }
        }

        return $this->metadataCache[$modelClass] = [
            'reflection' => $reflection,
            'casts' => $model->getCasts(),
            'propertyTags' => $propertyTags,
            'appends' => array_values($model->getAppends()),
            'fillable' => array_values($model->getFillable()),
            'hidden' => array_values($model->getHidden()),
            'visible' => array_values($model->getVisible()),
            'timestamps' => $timestamps,
        ];
    }

    /**
     * Converts an OA\Schema into a named OA\Property by copying every defined JSON-Schema field.
     */
    private function propertyFromSchema(string $name, OA\Schema $schema): OA\Property
    {
        return copy_schema_fields(
            $schema,
            new OA\Property(['property' => $name]),
        );
    }

    /**
     * Maps an Eloquent cast string to an OA\Property, or null when the cast type is not
     * recognised. The model's `@property` tag disambiguates list from map for JSON casts.
     */
    private function castToProperty(string $name, string $cast, ?TypeNode $declaredType = null): ?OA\Property
    {
        // The part before `:` is the bare keyword or class-string; `?: $cast` guards against an
        // empty token when strtok returns false.
        $castHead = strtok($cast, ':') ?: $cast;

        // Modern `casts()` style spells JSON casts as class-strings; getCasts() returns the FQCN,
        // which the keyword match below would never recognise.
        $classFormDefinition = $this->classFormCastDefinition($castHead, $declaredType);

        if ($classFormDefinition !== null) {
            return new OA\Property(['property' => $name, ...$classFormDefinition]);
        }

        // Normalise to lowercase keyword (e.g. `decimal:2` → `decimal`).
        $normalised = strtolower($castHead);

        // `encrypted:<type>` decrypts to the inner type; bare `encrypted` is just a string.
        if ($normalised === 'encrypted' && str_starts_with($cast, 'encrypted:')) {
            $inner = substr($cast, strlen('encrypted:'));

            return $this->castToProperty($name, $inner, $declaredType);
        }

        // Scalar keywords resolve via the common map; cast-only keywords are handled here.
        $definition = $this->scalarKeywordToDefinition($normalised) ?? match ($normalised) {
            'real' => ['type' => 'number'],
            'decimal',
            'hashed',
            'encrypted' => ['type' => 'string'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'datetime',
            'immutable_date',
            'immutable_datetime',
            'timestamp',
            'custom_datetime' => ['type' => 'string', 'format' => 'date-time'],
            'array',
            'json',
            'collection' => $this->jsonCastDefinition($declaredType),
            'object' => ['type' => 'object'],
            default => null,
        };

        if ($definition === null) {
            return null;
        }

        return new OA\Property(['property' => $name, ...$definition]);
    }

    /**
     * Schema definition for a class-form object cast (modern `casts()` style using class-strings).
     * Returns null for unknown castables; the caller falls back to the `@property` tag.
     *
     * @return null|array<string, mixed>
     */
    private function classFormCastDefinition(string $castClass, ?TypeNode $declaredType): ?array
    {
        if (!class_exists($castClass)) {
            return null;
        }

        return match (true) {
            is_a($castClass, AsCollection::class, allow_string: true),
            is_a($castClass, AsEncryptedCollection::class, allow_string: true),
            is_a($castClass, AsArrayObject::class, allow_string: true),
            is_a($castClass, AsEncryptedArrayObject::class, allow_string: true) => $this->jsonCastDefinition(
                $declaredType,
            ),
            is_a($castClass, AsStringable::class, allow_string: true) => ['type' => 'string'],
            default => null,
        };
    }

    /**
     * Schema definition for an `array`/`json`/`collection` cast: a list when the `@property`
     * tag is list-shaped, otherwise `object`. Items typed only for scalar element keywords.
     *
     * @return array<string, mixed>
     */
    private function jsonCastDefinition(?TypeNode $declaredType): array
    {
        $elementNode = $declaredType === null
            ? null
            : $this->typeNodeResolver->listValueType($declaredType);

        if ($elementNode === null) {
            return ['type' => 'object'];
        }

        // swagger-php rejects an items-less array; provide an empty OA\Items for non-scalar elements.
        $definition = ['type' => 'array', 'items' => new OA\Items([])];

        if ($elementNode instanceof IdentifierTypeNode) {
            $itemDefinition = $this->scalarKeywordToDefinition($elementNode->name);

            if ($itemDefinition !== null) {
                $definition['items'] = new OA\Items($itemDefinition);
            }
        }

        return $definition;
    }

    /**
     * Maps a scalar PHPDoc/cast keyword to an OpenAPI type definition, or null for non-scalars.
     *
     * @return null|array<string, string>
     */
    private function scalarKeywordToDefinition(string $keyword): ?array
    {
        return match (strtolower($keyword)) {
            'int', 'integer' => ['type' => 'integer'],
            'float', 'double' => ['type' => 'number'],
            'string' => ['type' => 'string'],
            'bool', 'boolean',
            'true', 'false' => ['type' => 'boolean'],
            default => null,
        };
    }

    /**
     * Builds a named OA\Property from a docblock type node, or null when unresolvable.
     *
     * @param ReflectionClass<Model> $reflection
     *
     * @throws ReflectionException
     */
    private function propertyFromTag(
        string $name,
        TypeNode $node,
        ReflectionClass $reflection,
    ): ?OA\Property {
        // TypeNodeToSchema handles array shapes and list/map forms; classTagSchema handles
        // model $refs and non-model classes. Nullability is applied by the resolver.
        $schema = $this->typeNodeToSchema->resolve(
            $node,
            $reflection,
            $this->classTagSchema(...),
        );

        if ($schema === null) {
            $this->logger->warning('EloquentModelToSchema: unresolvable @property type, using empty fallback', [
                'model' => $reflection->getName(),
                'property' => $name,
                'type' => (string) $node,
            ]);

            return null;
        }

        return $this->propertyFromSchema($name, $schema);
    }

    /**
     * Resolves an accessor's return type to an OA\Property, checking both the studly-cased and
     * legacy `get…Attribute` forms. Returns null when no typed accessor exists, or when the
     * return type is `Attribute` (new-style `Attribute::get()`; value type is not reflectable).
     *
     * @param ReflectionClass<Model> $reflection
     *
     * @throws ReflectionException
     */
    private function propertyFromAccessor(ReflectionClass $reflection, string $name): ?OA\Property
    {
        $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
        $candidates = [$studly, 'get' . $studly . 'Attribute'];

        foreach ($candidates as $methodName) {
            if (!$reflection->hasMethod($methodName)) {
                continue;
            }

            $returnType = $reflection->getMethod($methodName)->getReturnType();

            if (!$returnType instanceof ReflectionNamedType) {
                continue;
            }

            // New-style Attribute::get() accessors don't expose the value type.
            if ($returnType->getName() === Attribute::class) {
                continue;
            }

            try {
                $type = $this->typeResolver->resolve($returnType);
            } catch (UnsupportedException) {
                continue;
            }

            return $this->propertyFromSchema($name, $this->jsonSchemaFromType->fromType($type));
        }

        return null;
    }

    /**
     * Nullable date-time property for a timestamp column (unsaved models and NULL columns carry no value).
     */
    private function timestampProperty(string $name): OA\Property
    {
        return new OA\Property(['property' => $name, 'type' => ['string', 'null'], 'format' => 'date-time']);
    }

    /**
     * Related models become a pooled `$ref`; other classes are shaped by JsonSchemaFromType.
     * Supplied as the class-schema strategy to TypeNodeToSchema.
     *
     * @throws ReflectionException
     */
    private function classTagSchema(string $className): OA\Schema
    {
        if (is_a($className, Model::class, allow_string: true)) {
            /** @var class-string<Model> $className */
            return new OA\Schema([
                'ref' => $this->registry->qualifyKey($this->build($className)),
            ]);
        }

        return $this->jsonSchemaFromType->fromType(Type::object($className));
    }

    /**
     * @param class-string<Model> $modelClass
     *
     * @throws ReflectionException
     */
    public function build(string $modelClass): string
    {
        return $this->registry->buildOnce(
            $modelClass,
            fn(): OA\Schema => $this->schemaFor($modelClass),
        );
    }

    /**
     * @param class-string<Model> $modelClass
     *
     * @throws ReflectionException
     */
    private function schemaFor(string $modelClass): OA\Schema
    {
        $metadata = $this->metadataFor($modelClass);

        if ($metadata === null) {
            return new OA\Schema(['type' => 'object']);
        }

        $reflection = $metadata['reflection'];
        $casts = $metadata['casts'];
        $hidden = $metadata['hidden'];
        $visible = $metadata['visible'];
        $fillable = $metadata['fillable'];
        $appends = $metadata['appends'];
        $propertyTags = $metadata['propertyTags'];
        $timestamps = $metadata['timestamps'];

        $allNames = array_unique(
            array_merge(
                array_keys($casts),
                $fillable,
                $appends,
                array_keys($propertyTags),
                $timestamps,
            ),
        );

        if ($visible !== []) {
            $allNames = array_values(
                array_filter(
                    $allNames,
                    static fn(string $name): bool => in_array($name, $visible, strict: true),
                ),
            );
        }

        $names = array_values(array_diff($allNames, $hidden));
        $examples = $this->factoryExampleReader->examplesFor($modelClass);
        $properties = [];

        foreach ($names as $name) {
            $castString = $casts[$name] ?? null;
            $tag = $propertyTags[$name] ?? null;

            if ($castString !== null) {
                /** @noinspection NotOptimalIfConditionsInspection */
                if (
                    enum_exists($castString)
                    && is_a($castString, BackedEnum::class, allow_string: true)
                ) {
                    $properties[] = $this->propertyFromSchema(
                        $name,
                        $this->jsonSchemaFromType->fromBackedEnumComponent($castString),
                    );

                    continue;
                }

                $property = $this->castToProperty($name, $castString, $tag?->type);

                // Unrecognised cast: defer to the `@property` tag, or fall back to untyped.
                if ($property === null && $tag !== null) {
                    $property = $this->propertyFromTag($name, $tag->type, $reflection);
                }

                $properties[] = $property ?? new OA\Property(['property' => $name]);

                continue;
            }

            if ($tag !== null) {
                $property = $this->propertyFromTag($name, $tag->type, $reflection);

                if ($property !== null) {
                    $properties[] = $property;

                    continue;
                }
            }

            if (in_array($name, $appends, strict: true)) {
                $property = $this->propertyFromAccessor($reflection, $name);

                if ($property !== null) {
                    $properties[] = $property;

                    continue;
                }
            }

            // Timestamp columns carry no explicit metadata; type below cast/tag, above untyped.
            if (in_array($name, $timestamps, strict: true)) {
                $properties[] = $this->timestampProperty($name);

                continue;
            }

            $properties[] = new OA\Property(['property' => $name]);
        }

        if ($examples !== []) {
            foreach ($properties as $property) {
                if (array_key_exists($property->property, $examples)) {
                    $property->example = $examples[$property->property];
                }
            }
        }

        foreach ($properties as $property) {
            $this->applyTagDescription($property, $propertyTags[$property->property] ?? null);
        }

        // Non-nullable @property tags mark the property required, regardless of the cast type.
        $required = [];

        foreach ($names as $name) {
            $tag = $propertyTags[$name] ?? null;

            if ($tag !== null && !$this->typeNodeResolver->isNullable($tag->type)) {
                $required[] = $name;
            }
        }

        $schemaArgs = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schemaArgs['required'] = $required;
        }

        return new OA\Schema($schemaArgs);
    }
}
