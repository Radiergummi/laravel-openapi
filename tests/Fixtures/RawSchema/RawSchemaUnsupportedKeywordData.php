<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\RawSchema;

use Radiergummi\OpenApi\Attributes\RawSchema;
use Spatie\LaravelData\Data;

/**
 * Carries an `if` keyword swagger-php cannot serialise. The keyword is dropped at build time
 * (degrade-and-log) and flagged by the `schema.raw-keyword-unsupported` lint rule.
 */
#[RawSchema([
    'type' => 'object',
    'properties' => [
        'kind' => ['type' => 'string'],
    ],
    'if' => ['properties' => ['kind' => ['const' => 'a']]],
])]
final class RawSchemaUnsupportedKeywordData extends Data
{
    public function __construct(
        public string $kind,
    ) {}
}
