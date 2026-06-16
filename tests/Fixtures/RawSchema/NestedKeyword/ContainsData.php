<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\RawSchema\NestedKeyword;

use Radiergummi\OpenApi\Attributes\RawSchema;
use Spatie\LaravelData\Data;

/**
 * `contains` with a nested item schema on an array type.
 */
#[RawSchema([
    'type' => 'array',
    'items' => ['type' => 'integer'],
    'contains' => ['type' => 'integer', 'minimum' => 1],
])]
final class ContainsData extends Data
{
    public function __construct(
        public string $ignored,
    ) {}
}
