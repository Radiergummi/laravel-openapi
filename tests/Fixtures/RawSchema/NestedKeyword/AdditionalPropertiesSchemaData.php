<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\RawSchema\NestedKeyword;

use Radiergummi\OpenApi\Attributes\RawSchema;
use Spatie\LaravelData\Data;

/**
 * `additionalProperties` as a nested schema object (not a bool). The nested schema is itself an
 * array type, forcing the items-less-array guard to fire on the converted child.
 */
#[RawSchema([
    'type' => 'object',
    'additionalProperties' => [
        'type' => 'array',
        'items' => ['type' => 'string'],
    ],
])]
final class AdditionalPropertiesSchemaData extends Data
{
    public function __construct(
        public string $ignored,
    ) {}
}
