<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Model;
use OpenApi\Annotations as OA;
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
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

use function array_diff;
use function array_filter;
use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function explode;
use function in_array;
use function ltrim;
use function str_contains;
use function strtolower;

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
        // @phpstan-ignore property.onlyWritten (injected now for constructor stability; used by later @property/relation tasks)
        private readonly JsonSchemaFromType $jsonSchemaFromType,
        // @phpstan-ignore property.onlyWritten (injected now for constructor stability; used by later @property/relation tasks)
        private readonly TypeResolver $typeResolver,
        // @phpstan-ignore property.onlyWritten (injected now for constructor stability; used by later @property/relation tasks)
        private readonly TypeNodeResolver $typeNodeResolver,
        private readonly DocBlockParser $docBlockParser,
        // @phpstan-ignore property.onlyWritten (injected now for constructor stability; used by later relation/logging tasks)
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
                $properties[] = $this->castToProperty($name, $castString) ?? new OA\Property(['property' => $name]);

                continue;
            }

            $tag = $propertyTags[$name] ?? null;

            if ($tag !== null) {
                $property = $this->propertyFromTag($name, $tag->type);

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
     * Builds an OA\Property from a docblock type node for scalar types.
     * Returns null when the type is a class, generic, array, or otherwise
     * not a recognised scalar keyword — those fall through to the empty fallback.
     */
    private function propertyFromTag(string $name, TypeNode $node): ?OA\Property
    {
        $identifier = $this->unwrapToIdentifier($node);

        if ($identifier === null) {
            return null;
        }

        $definition = $this->scalarTypeDefinition($identifier->name);

        if ($definition === null) {
            return null;
        }

        $property = new OA\Property(['property' => $name, ...$definition]);

        if ($this->isNullable($node)) {
            $property->nullable = true;
        }

        return $property;
    }

    /**
     * Unwraps a nullable or union-with-null wrapper to reach the inner
     * IdentifierTypeNode. Returns null for compound/generic/array nodes.
     */
    private function unwrapToIdentifier(TypeNode $node): ?IdentifierTypeNode
    {
        if ($node instanceof IdentifierTypeNode) {
            return $node;
        }

        if ($node instanceof NullableTypeNode) {
            return $this->unwrapToIdentifier($node->type);
        }

        if ($node instanceof UnionTypeNode) {
            $nonNull = null;

            foreach ($node->types as $member) {
                if ($member instanceof IdentifierTypeNode && strtolower($member->name) === 'null') {
                    continue;
                }

                if ($nonNull !== null) {
                    // More than one non-null member — not a simple nullable scalar.
                    return null;
                }

                $nonNull = $member;
            }

            return $nonNull instanceof IdentifierTypeNode ? $nonNull : null;
        }

        return null;
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
