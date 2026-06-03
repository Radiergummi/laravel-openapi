<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Model;
use OpenApi\Annotations as OA;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use ReflectionClass;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

use function array_diff;
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
        $docPropertyNames = [];

        if ($docComment !== false) {
            $parsed = $this->docBlockParser->parse($docComment);

            foreach ($parsed->tagValues('@property') as $tag) {
                if ($tag instanceof PropertyTagValueNode) {
                    $docPropertyNames[] = ltrim($tag->propertyName, '$');
                }
            }

            foreach ($parsed->tagValues('@property-read') as $tag) {
                if ($tag instanceof PropertyTagValueNode) {
                    $docPropertyNames[] = ltrim($tag->propertyName, '$');
                }
            }
        }

        // Union of all known property names.
        $allNames = array_unique(array_merge(
            array_keys($casts),
            $fillable,
            $appends,
            $docPropertyNames,
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
            $properties[] = $castString !== null
                ? ($this->castToProperty($name, $castString) ?? new OA\Property(['property' => $name]))
                : new OA\Property(['property' => $name]);
        }

        return new OA\Schema([
            'type' => 'object',
            'properties' => $properties,
        ]);
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
