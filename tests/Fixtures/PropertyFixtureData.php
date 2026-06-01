<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Attributes\RequestField;
use Spatie\LaravelData\Data;

/**
 * Fixture {@see Data} class exercising the {@see RequestField} attribute across the documented
 * constraint fields.
 */
final class PropertyFixtureData extends Data
{
    public function __construct(
        #[RequestField(
            description: 'Display name shown in lists.',
            example: 'Aerospace Q1',
            maxLength: 250,
        )]
        public string $name,
        #[RequestField(
            example: 'https://hooks.example.com/projects',
            format: 'uri',
        )]
        public ?string $callbackUrl = null,
        public int $limit = 25,
    ) {}
}
