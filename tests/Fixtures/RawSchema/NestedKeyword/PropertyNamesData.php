<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\RawSchema\NestedKeyword;

use Radiergummi\OpenApi\Attributes\RawSchema;
use Spatie\LaravelData\Data;

/**
 * `propertyNames` constraining keys with a nested schema.
 */
#[RawSchema([
    'type' => 'object',
    'propertyNames' => ['pattern' => '^[a-z]+$'],
    'additionalProperties' => ['type' => 'string'],
])]
final class PropertyNamesData extends Data
{
    public function __construct(
        public string $ignored,
    ) {}
}
