<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\RawSchema;

use Radiergummi\OpenApi\Attributes\RawSchema;
use Spatie\LaravelData\Data;

/**
 * A Spatie Data class whose body is replaced by a literal `#[RawSchema]`. The `secret` property
 * the convention would otherwise infer is absent from the literal body, proving inference is
 * skipped.
 */
#[RawSchema([
    'type' => 'object',
    'required' => ['kind'],
    'properties' => [
        'kind' => ['type' => 'string', 'enum' => ['a', 'b']],
    ],
])]
final class RawSchemaData extends Data
{
    public function __construct(
        public string $kind,
        public string $secret,
    ) {}
}
