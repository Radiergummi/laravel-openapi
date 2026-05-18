<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;
use OpenApi\Annotations as OA;
use RuntimeException;

/**
 * Marks a base class as a polymorphic type root and emits a `oneOf` + `discriminator` schema
 * instead of a flat bag-of-fields object.
 *
 * **Usage — place on the abstract base class or shared interface:**
 *
 * ```php
 * #[OpenApi\Discriminator(
 *     propertyName: 'type',
 *     mapping: [
 *         'circle' => CircleData::class,
 *         'rectangle' => RectangleData::class,
 *     ],
 * )]
 * abstract class ShapeData extends Data { … }
 * ```
 *
 * The generator will:
 * 1. Register each mapped variant class as its own component schema.
 * 2. Replace the base class's schema with a `oneOf` listing each variant's `$ref`.
 * 3. Emit a `discriminator` object with `propertyName` and a `mapping` that points each
 *    discriminator value to the `$ref` string of its component schema (e.g.
 *    `circle: '#/components/schemas/CircleData'`).
 *
 * **Mapping keys** are the discriminator string values clients send (e.g. `'circle'`).
 * **Mapping values** are the fully qualified class names of the variant {@see Data} or
 * {@see ApiResource} classes. They are resolved to `$ref` strings at generation time.
 *
 * **Supported base class types:**
 * - {@see Data} subclasses (processed by {@see SchemaFromDataClass})
 * - {@see ApiResource} subclasses (processed by {@see SchemaFromApiResource})
 *
 * Both extractors detect this attribute and defer to the `oneOf` path rather than the normal
 * property-walking path.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Discriminator
{
    /**
     * @param string                      $propertyName The JSON property that carries the
     *                                                  discriminator value (e.g. `'type'`).
     * @param array<string, class-string> $mapping      Map of discriminator value → variant class
     *                                                  FQN. Keys are the string values that appear
     *                                                  in the payload; values are class names
     *                                                  resolved to `$ref` strings at generation
     *                                                  time.
     */
    public function __construct(
        public string $propertyName,
        public array $mapping,
    ) {}

    /**
     * Assembles the `oneOf` + `discriminator` schema from a pre-resolved map of a variant class
     * FQN → component key.
     *
     * Callers are responsible for registering each variant class as its own component
     * schema (typically by calling their extractor's `build()` first); this helper only assembles
     * the wrapper schema once those keys are known.
     *
     * @param array<class-string, string> $variantKeys Map of variant class → component key.
     */
    public function assemble(array $variantKeys): OA\Schema
    {
        $oneOf = [];
        $mappingRefs = [];

        foreach ($this->mapping as $value => $variantClass) {
            $key = $variantKeys[$variantClass] ?? throw new RuntimeException("Variant class '{$variantClass}' was not built before assemble().");
            $ref = "#/components/schemas/{$key}";

            $oneOf[] = new OA\Schema(['ref' => $ref]);
            $mappingRefs[$value] = $ref;
        }

        return new OA\Schema([
            'oneOf' => $oneOf,
            'discriminator' => new OA\Discriminator([
                'propertyName' => $this->propertyName,
                'mapping' => $mappingRefs,
            ]),
        ]);
    }
}
