<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\RawSchema\NestedKeyword;

use Radiergummi\OpenApi\Attributes\RawSchema;
use Spatie\LaravelData\Data;

/**
 * `additionalProperties` as a nested schema object (not a bool). The nested schema is itself an
 * array type. These keywords are not converted to `OA\Schema`, so the nested array is preserved
 * as a raw array and the items-less-array guard in `apply()` does not descend into it.
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
