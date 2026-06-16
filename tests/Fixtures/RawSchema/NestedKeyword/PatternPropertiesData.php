<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\RawSchema\NestedKeyword;

use Radiergummi\OpenApi\Attributes\RawSchema;
use Spatie\LaravelData\Data;

/**
 * `patternProperties` mapping a regex to a nested schema.
 */
#[RawSchema([
    'type' => 'object',
    'patternProperties' => [
        '^x-' => ['type' => 'string'],
        '^count_' => ['type' => 'integer'],
    ],
])]
final class PatternPropertiesData extends Data
{
    public function __construct(
        public string $ignored,
    ) {}
}
