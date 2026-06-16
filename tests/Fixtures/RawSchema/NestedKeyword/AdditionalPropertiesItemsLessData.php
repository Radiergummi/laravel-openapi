<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\RawSchema\NestedKeyword;

use Radiergummi\OpenApi\Attributes\RawSchema;
use Spatie\LaravelData\Data;

/**
 * `additionalProperties` carrying an items-less `type: array`. This pins the current no-conversion
 * behaviour: because `additionalProperties` is assigned as a raw array (never built into an
 * `OA\Schema`), the items-less-array guard does not synthesise an `items` here, and the bare
 * `{type: array}` survives verbatim and still validates. If conversion is ever added, the
 * synthesised `items` will make this assertion fail at the exact boundary.
 */
#[RawSchema([
    'type' => 'object',
    'additionalProperties' => [
        'type' => 'array',
    ],
])]
final class AdditionalPropertiesItemsLessData extends Data
{
    public function __construct(
        public string $ignored,
    ) {}
}
