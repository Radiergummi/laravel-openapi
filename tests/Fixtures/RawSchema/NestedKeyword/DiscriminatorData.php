<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\RawSchema\NestedKeyword;

use Radiergummi\OpenApi\Attributes\RawSchema;
use Spatie\LaravelData\Data;

/**
 * `discriminator` as an object (propertyName + mapping) alongside a `oneOf` composition.
 */
#[RawSchema([
    'oneOf' => [
        [
            'type' => 'object',
            'required' => ['kind'],
            'properties' => [
                'kind' => ['type' => 'string'],
                'radius' => ['type' => 'number'],
            ],
        ],
        [
            'type' => 'object',
            'required' => ['kind'],
            'properties' => [
                'kind' => ['type' => 'string'],
                'side' => ['type' => 'number'],
            ],
        ],
    ],
    'discriminator' => [
        'propertyName' => 'kind',
        'mapping' => [
            'circle' => '#/components/schemas/Circle',
            'square' => '#/components/schemas/Square',
        ],
    ],
])]
final class DiscriminatorData extends Data
{
    public function __construct(
        public string $ignored,
    ) {}
}
