<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use OpenApi\Annotations as OA;

use function is_array;

/**
 * Builds an `OA\Schema` from a literal JSON-Schema array definition, recursively converting
 * `properties` into `OA\Property` and `items` into `OA\Items`. swagger-php 5.x rejects a raw
 * array left under `properties`/`items` (`properties is an object literal`), failing validation;
 * a proper object graph validates on both the 5.x and 6.x lines.
 *
 * Shared by the `#[Response(schema: [...])]` authoring path and the Tier-1 inline-JSON response
 * scan, which both express schemas as plain definition arrays first.
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

                /** @var mixed $childDefinition */
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

            $node->{$key} = $value;
        }
    }
}
