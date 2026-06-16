<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use OpenApi\Annotations as OA;
use OpenApi\Generator;

use function array_values;
use function is_array;

/**
 * Builds an `OA\Schema` object graph from a literal JSON-Schema array definition.
 *
 * swagger-php rejects raw arrays under `properties`, `allOf`, etc.; this converts them to
 * proper OA object nodes. Every `type: array` node is guaranteed an `items` object because
 * swagger-php rejects items-less array schemas on both supported majors (5.x and 6.x).
 *
 * @internal
 */
final readonly class SchemaFromArrayDefinition
{
    /**
     * @param array<string, mixed> $definition
     */
    public static function build(array $definition): OA\Schema
    {
        $schema = new OA\Schema([]);
        self::apply($schema, $definition);

        return $schema;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function apply(OA\Schema $node, array $definition): void
    {
        foreach ($definition as $key => $value) {
            if ($key === 'properties' && is_array($value)) {
                $properties = [];

                foreach ($value as $name => $childDefinition) {
                    $property = new OA\Property(['property' => (string) $name]);

                    if (is_array($childDefinition)) {
                        self::apply($property, $childDefinition);
                    }

                    $properties[] = $property;
                }

                $node->properties = $properties;

                continue;
            }

            if ($key === 'items' && is_array($value)) {
                $items = new OA\Items([]);
                self::apply($items, $value);
                $node->items = $items;

                continue;
            }

            if (($key === 'oneOf' || $key === 'anyOf' || $key === 'allOf') && is_array($value)) {
                $node->{$key} = array_map(
                    static fn(mixed $member): OA\Schema => is_array($member)
                        ? self::build($member)
                        : new OA\Schema([]),
                    array_values($value),
                );

                continue;
            }

            if ($key === 'not' && is_array($value)) {
                $node->not = self::build($value);

                continue;
            }

            $node->{$key} = $value;
        }

        if ($node->type === 'array' && Generator::isDefault($node->items)) {
            $node->items = new OA\Items([]);
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function buildProperty(string $propertyName, array $definition): OA\Property
    {
        $property = new OA\Property(['property' => $propertyName]);
        self::apply($property, $definition);

        return $property;
    }
}
