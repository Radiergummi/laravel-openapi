<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\RawSchema;

use Radiergummi\OpenApi\Attributes\RawSchema;
use Radiergummi\OpenApi\Attributes\RequestField;
use Spatie\LaravelData\Data;

/**
 * Both `#[RawSchema]` (class-level) and `#[RequestField]` (property-level) are present. The
 * class-level attribute wins via the early return, so the field attribute has no effect.
 */
#[RawSchema([
    'type' => 'object',
    'properties' => [
        'only' => ['type' => 'string'],
    ],
])]
final class RawSchemaWithFieldAttributeData extends Data
{
    public function __construct(
        #[RequestField(description: 'This description must not appear in the output.')]
        public string $annotated,
    ) {}
}
