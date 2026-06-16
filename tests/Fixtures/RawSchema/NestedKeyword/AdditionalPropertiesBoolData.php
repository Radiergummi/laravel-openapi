<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\RawSchema\NestedKeyword;

use Radiergummi\OpenApi\Attributes\RawSchema;
use Spatie\LaravelData\Data;

/**
 * `additionalProperties: false` (bool form). The bool form is valid and must pass through
 * unchanged; a future schema-conversion of `additionalProperties` must not wrap it into a schema.
 */
#[RawSchema([
    'type' => 'object',
    'properties' => [
        'name' => ['type' => 'string'],
    ],
    'additionalProperties' => false,
])]
final class AdditionalPropertiesBoolData extends Data
{
    public function __construct(
        public string $name,
    ) {}
}
