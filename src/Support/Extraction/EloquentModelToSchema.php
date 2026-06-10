<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use BackedEnum;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use OpenApi\Annotations as OA;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
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
use function enum_exists;
use function explode;
use function in_array;
use function is_a;
use function ltrim;
use function Radiergummi\OpenApi\copy_schema_fields;
use function str_contains;
use function str_replace;
use function strtolower;
use function ucwords;

/**
 * Builds an OpenAPI schema for an Eloquent model from its metadata: cast keys, fillable fields,
 * appends, and docblock at-property names.
 *
 * @internal
 */
#[Scoped]
final readonly class EloquentModelToSchema
{
    /**
     * Per-model metadata memo for {@see propertyFor()} and {@see schemaFor()}; null marks a
     * non-instantiable model (warned once on first access).
     *
     * @var array<class-string<Model>, null|array{
     *     reflection: ReflectionClass<Model>,
     *     casts: array<string, string>,
     *     propertyTags: array<string, PropertyTagValueNode>,
     *     appends: list<string>,
     *     fillable: list<string>,
     *     hidden: list<string>,
     *     visible: list<string>,
     * }>
     */
    private array $metadataCache = [];

    public function __construct(
        private ComponentSchemaRegistry $registry,
        private JsonSchemaFromType $jsonSchemaFromType,
        private TypeNodeToSchema $typeNodeToSchema,
        private TypeResolver $typeResolver,
        private TypeNodeResolver $typeNodeResolver,
        private DocBlockParser $docBlockParser,
        private LoggerInterface $logger,
    ) {}

    /**
     * Registers the model's schema and returns the component key.
     *
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
     * The schema for one model property by name — typed from `$casts`, a `@property` /
     * `@property-read` tag, or an appended accessor, in that order — or null when the model
     * carries no metadata under that name.
     *
     * Unlike {@see schemaFor()}, no `$hidden`/`$visible` filtering applies: those govern the
     * *model's own* serialization, while a caller resolving a name it found elsewhere (a
     * Resource `toArray()` key) has already decided the property is output.
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

        $castString = $metadata['casts'][$propertyName] ?? null;

        if ($castString !== null) {
            if (enum_exists($castString) && is_a($castString, BackedEnum::class, allow_string: true)) {
                return $this->propertyFromSchema(
                    $propertyName,
                    $this->jsonSchemaFromType->fromBackedEnumClass($castString),
                );
            }

            return $this->castToProperty($propertyName, $castString)
                ?? new OA\Property(['property' => $propertyName]);
        }

        $tag = $metadata['propertyTags'][$propertyName] ?? null;

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

        // The name is known to the model (tagged, appended, or fillable) but its type is not
        // derivable — an untyped property, the same fallback schemaFor() uses.
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
     * Gathers (and memoises) the reflection-level metadata for a model class, or null when the
     * model is not instantiable.
     *
     * An abstract or otherwise non-instantiable model (reachable as a return type or a
     * docblock relation annotation) would throw an Error from `new $modelClass()` — which the
     * resolver fault boundary deliberately does not catch. Callers degrade gracefully instead,
     * so one such model does not abort the whole generation run.
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

        // An abstract or otherwise non-instantiable model (reachable as a return type or a
        // `@property-read` relation) would throw an Error from `new $modelClass()`, which the
        // resolver fault boundary deliberately does not catch. Degrade to an unknown-shape schema
        // here instead, so one such model does not abort the whole generation run.
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

        return $this->metadataCache[$modelClass] = [
            'reflection' => $reflection,
            'casts' => $model->getCasts(),
            'propertyTags' => $propertyTags,
            'appends' => array_values($model->getAppends()),
            'fillable' => array_values($model->getFillable()),
            'hidden' => array_values($model->getHidden()),
            'visible' => array_values($model->getVisible()),
        ];
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

        // Union of all known property names.
        $allNames = array_unique(
            array_merge(
                array_keys($casts),
                $fillable,
                $appends,
                array_keys($propertyTags),
            ),
        );

        // Apply visibility/hidden filters.
        if ($visible !== []) {
            $allNames = array_values(
                array_filter(
                    $allNames,
                    static fn(string $name): bool => in_array($name, $visible, strict: true),
                ),
            );
        }

        $names = array_values(array_diff($allNames, $hidden));
        $properties = [];

        foreach ($names as $name) {
            $castString = $casts[$name] ?? null;

            if ($castString !== null) {
                // Enum-class cast takes priority: reference the shared reusable enum component.
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

                $properties[] = $this->castToProperty($name, $castString)
                    ?? new OA\Property(['property' => $name]);

                continue;
            }

            $tag = $propertyTags[$name] ?? null;

            if ($tag !== null) {
                $property = $this->propertyFromTag($name, $tag->type, $reflection);

                if ($property !== null) {
                    $properties[] = $property;

                    continue;
                }
            }

            // For appended attributes not typed via @property, try the accessor method.
            if (in_array($name, $appends, strict: true)) {
                $property = $this->propertyFromAccessor($reflection, $name);

                if ($property !== null) {
                    $properties[] = $property;

                    continue;
                }
            }

            $properties[] = new OA\Property(['property' => $name]);
        }

        // A property is required when it has a @property/@property-read annotation whose type is
        // non-nullable - regardless of whether the schema type came from a cast.
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

    /**
     * Builds a named OA\Property from a docblock type node via {@see TypeNodeToSchema}.
     *
     * Returns null when the node is unresolvable, so the caller falls through to the empty-property
     * fallback.
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
        // Array shapes (array{…}), list/array-of forms, and string-keyed maps are resolved by
        // TypeNodeToSchema; scalar keywords, related-model `$ref`s, and non-model classes are
        // resolved through the class-schema strategy below. Nullability is applied by the resolver.
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
     * Maps a resolved leaf class to a schema: a related model becomes a pooled `$ref`, any other
     * class is shaped by {@see JsonSchemaFromType}. Supplied as the class-schema strategy to
     * {@see TypeNodeToSchema}.
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
     * Maps a scalar PHPDoc/cast keyword to an OpenAPI type definition array,
     * or returns null for class names and non-scalar keywords.
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
     * Resolves the return type of accessor method to an OA\Property, or returns null when no
     * reflectable typed accessor exists for the property name.
     *
     * Checks the new-style studly cased method (e.g. `readingTime`) and the legacy
     * `getReadingTimeAttribute` form. If the return type is `Attribute` (the new
     * `Attribute::get(...)` style), the value type is not reflectable, so null is returned.
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
     * Converts an OA\Schema into a named OA\Property by copying every defined JSON-Schema field.
     *
     * OA\Property extends OA\Schema, so the field set is identical; swagger-php internals are
     * underscore-prefixed and skipped, as is the component-key `schema` field.
     */
    private function propertyFromSchema(string $name, OA\Schema $schema): OA\Property
    {
        return copy_schema_fields(
            $schema,
            new OA\Property(['property' => $name]),
        );
    }

    /**
     * Maps an Eloquent cast string to an OA\Property with the given name, or returns null when the
     * cast type is not recognized.
     */
    private function castToProperty(string $name, string $cast): ?OA\Property
    {
        // Normalise: take the part before `:` (e.g. `decimal:2` → `decimal`) and lowercase.
        $normalised = strtolower(
            str_contains($cast, ':') ? explode(':', $cast, 2)[0] : $cast,
        );

        // Shared scalar keywords (int/float/string/bool) resolve via the common map; the cast-only
        // keywords (decimal/date/datetime/array/…) are handled here.
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
            'object',
            'collection' => ['type' => 'object'],
            default => null,
        };

        if ($definition === null) {
            return null;
        }

        return new OA\Property(['property' => $name, ...$definition]);
    }
}
