<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use BackedEnum;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

use function array_diff;
use function array_filter;
use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function class_exists;
use function enum_exists;
use function explode;
use function in_array;
use function is_a;
use function ltrim;
use function str_contains;
use function str_replace;
use function strtolower;
use function ucwords;

/**
 * Builds an OpenAPI schema for an Eloquent model from its metadata:
 * cast keys, fillable fields, appends, and docblock @property names.
 *
 * @internal
 */
#[Scoped]
final class EloquentModelToSchema
{
    public function __construct(
        private readonly ComponentSchemaRegistry $registry,
        private readonly JsonSchemaFromType $jsonSchemaFromType,
        private readonly TypeResolver $typeResolver,
        private readonly TypeNodeResolver $typeNodeResolver,
        private readonly DocBlockParser $docBlockParser,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Registers the model's schema and returns the component key.
     *
     * @param class-string<Model> $modelClass
     */
    public function build(string $modelClass): string
    {
        return $this->registry->buildOnce($modelClass, fn(): OA\Schema => $this->schemaFor($modelClass));
    }

    /**
     * @param class-string<Model> $modelClass
     *
     * @throws ReflectionException
     */
    private function schemaFor(string $modelClass): OA\Schema
    {
        $model = new $modelClass();

        $casts = $model->getCasts();
        $hidden = $model->getHidden();
        $visible = $model->getVisible();
        $fillable = $model->getFillable();

        $appends = $model->getAppends();

        $reflection = new ReflectionClass($modelClass);
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

        // Union of all known property names.
        $allNames = array_unique(array_merge(
            array_keys($casts),
            $fillable,
            $appends,
            array_keys($propertyTags),
        ));

        // Apply visibility/hidden filters.
        if ($visible !== []) {
            $allNames = array_values(array_filter(
                $allNames,
                static fn(string $name): bool => in_array($name, $visible, strict: true),
            ));
        }

        $names = array_values(array_diff($allNames, $hidden));

        $properties = [];

        foreach ($names as $name) {
            $castString = $casts[$name] ?? null;

            if ($castString !== null) {
                // Enum-class cast takes priority: produce an inline enum schema.
                if (enum_exists($castString) && is_a($castString, BackedEnum::class, allow_string: true)) {
                    $properties[] = $this->propertyFromSchema($name, $this->jsonSchemaFromType->fromBackedEnumClass($castString));

                    continue;
                }

                $properties[] = $this->castToProperty($name, $castString) ?? new OA\Property(['property' => $name]);

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

        // A property is required when it has a @property/@property-read annotation whose
        // type is non-nullable — regardless of whether the schema type came from a cast.
        $required = [];

        foreach ($names as $name) {
            $tag = $propertyTags[$name] ?? null;

            if ($tag !== null && ! $this->isNullable($tag->type)) {
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
     * Builds an OA\Property from a docblock type node.
     *
     * Precedence:
     * 1. Scalar keyword → inline type definition.
     * 2. Eloquent Model class → `$ref` to the related model's component schema (recursive, cycle-guarded).
     * 3. Non-model class (e.g. Carbon, UuidInterface) → schema via JsonSchemaFromType::fromType.
     * 4. Unresolvable node → returns null, caller falls through to the empty fallback.
     *
     * Nullability is preserved on all paths.
     *
     * @param ReflectionClass<Model> $reflection
     *
     * @throws ReflectionException
     */
    private function propertyFromTag(string $name, TypeNode $node, ReflectionClass $reflection): ?OA\Property
    {
        $identifier = $this->unwrapToIdentifier($node);

        if ($identifier === null) {
            return null;
        }

        $definition = $this->scalarTypeDefinition($identifier->name);

        if ($definition !== null) {
            $property = new OA\Property(['property' => $name, ...$definition]);

            if ($this->isNullable($node)) {
                $property->nullable = true;
            }

            return $property;
        }

        // Not a scalar — try to resolve the identifier as a class name.
        $className = $this->typeNodeResolver->resolveClassName($node, $reflection);

        if ($className === null || ! class_exists($className)) {
            $this->logger->warning('EloquentModelToSchema: unresolvable @property type, using empty fallback', [
                'model' => $reflection->getName(),
                'property' => $name,
                'type' => (string) $node,
            ]);

            return null;
        }

        if (is_a($className, Model::class, allow_string: true)) {
            /** @var class-string<Model> $className */
            $key = $this->build($className);
            $schema = new OA\Schema(['ref' => $this->registry->qualifyKey($key)]);
        } else {
            $schema = $this->jsonSchemaFromType->fromType(Type::object($className));
        }

        $property = $this->propertyFromSchema($name, $schema);

        if ($this->isNullable($node)) {
            $property->nullable = true;
        }

        return $property;
    }

    /**
     * Unwraps a nullable or union-with-null wrapper to reach the inner
     * IdentifierTypeNode. Returns null for compound/generic/array nodes.
     *
     * Uses {@see TypeNodeResolver::unwrapNullable()} as the shared primitive for
     * the nullable-descend step.
     *
     * Scalar fast-path: yields the bare IdentifierTypeNode so the scalar keyword check
     * can run before resolveClassName() (class path) is tried on the original node.
     */
    private function unwrapToIdentifier(TypeNode $node): ?IdentifierTypeNode
    {
        $inner = $this->typeNodeResolver->unwrapNullable($node);

        return $inner instanceof IdentifierTypeNode ? $inner : null;
    }

    /**
     * Returns true when the type node represents a nullable type
     * (NullableTypeNode or a union containing a `null` identifier).
     */
    private function isNullable(TypeNode $node): bool
    {
        if ($node instanceof NullableTypeNode) {
            return true;
        }

        if ($node instanceof UnionTypeNode) {
            foreach ($node->types as $member) {
                if ($member instanceof IdentifierTypeNode && strtolower($member->name) === 'null') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Maps a scalar PHPDoc keyword to an OpenAPI type definition array,
     * or returns null for class names and non-scalar keywords.
     *
     * @return null|array<string, string>
     */
    private function scalarTypeDefinition(string $keyword): ?array
    {
        return match (strtolower($keyword)) {
            'int', 'integer'        => ['type' => 'integer'],
            'float', 'double'       => ['type' => 'number'],
            'string'                => ['type' => 'string'],
            'bool', 'boolean',
            'true', 'false'         => ['type' => 'boolean'],
            default                 => null,
        };
    }

    /**
     * Resolves the return type of an accessor method to an OA\Property, or returns null when
     * no reflectable typed accessor exists for the property name.
     *
     * Checks the new-style studly-cased method (e.g. `readingTime`) and the legacy
     * `getReadingTimeAttribute` form. If the return type is `Attribute` (the new
     * `Attribute::get(...)` style), the value type is not reflectable so null is returned.
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
            if (! $reflection->hasMethod($methodName)) {
                continue;
            }

            $returnType = $reflection->getMethod($methodName)->getReturnType();

            if (! $returnType instanceof ReflectionNamedType) {
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
     * Converts an OA\Schema into an OA\Property by copying non-UNDEFINED JSON-Schema fields.
     */
    private function propertyFromSchema(string $name, OA\Schema $schema): OA\Property
    {
        $props = ['property' => $name];

        // The fields JsonSchemaFromType / fromBackedEnumClass actually emit. Extend this
        // list if those methods start producing additional schema keywords.
        foreach (['type', 'format', 'enum', 'description', 'items', 'ref', 'oneOf', 'nullable'] as $field) {
            $value = $schema->{$field};

            if ($value !== Generator::UNDEFINED) {
                $props[$field] = $value;
            }
        }

        return new OA\Property($props);
    }

    /**
     * Maps an Eloquent cast string to an OA\Property with the given name,
     * or returns null when the cast type is not recognised.
     */
    private function castToProperty(string $name, string $cast): ?OA\Property
    {
        // Normalise: take the part before `:` (e.g. `decimal:2` → `decimal`) and lowercase.
        $normalised = strtolower(
            str_contains($cast, ':') ? explode(':', $cast, 2)[0] : $cast,
        );

        $definition = match ($normalised) {
            'int', 'integer'
                => ['type' => 'integer'],
            'real', 'float', 'double'
                => ['type' => 'number'],
            'bool', 'boolean'
                => ['type' => 'boolean'],
            'string', 'decimal', 'hashed', 'encrypted'
                => ['type' => 'string'],
            'date'
                => ['type' => 'string', 'format' => 'date'],
            'datetime', 'immutable_date', 'immutable_datetime', 'timestamp', 'custom_datetime'
                => ['type' => 'string', 'format' => 'date-time'],
            'array', 'json', 'object', 'collection'
                => ['type' => 'object'],
            default => null,
        };

        if ($definition === null) {
            return null;
        }

        return new OA\Property(['property' => $name, ...$definition]);
    }
}
