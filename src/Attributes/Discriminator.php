<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\ComponentReference;
use RuntimeException;

/**
 * Marks a polymorphic base class (Spatie Data or ApiResource) so the generator emits a `oneOf`
 * + `discriminator` schema instead of a flat object. `mapping` keys are the discriminator string
 * values clients send; values are variant FQCNs resolved to `$ref` strings at generation time.
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
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Discriminator
{
    /**
     * @param non-empty-string                      $propertyName
     * @param array<non-empty-string, class-string> $mapping
     */
    public function __construct(
        public string $propertyName,
        public array $mapping,
    ) {}

    /**
     * Callers must register each variant as its own component schema before calling this — the
     * helper only assembles the wrapper once the keys are known.
     *
     * @param array<class-string, string> $variantKeys
     *
     * @throws RuntimeException
     */
    public function assemble(array $variantKeys): OA\Schema
    {
        $oneOf = [];
        $mappingRefs = [];

        foreach ($this->mapping as $value => $variantClass) {
            $key = $variantKeys[$variantClass] ?? throw new RuntimeException(
                "Variant class '{$variantClass}' was not built before assemble().",
            );
            $ref = ComponentReference::pointer($key);

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
