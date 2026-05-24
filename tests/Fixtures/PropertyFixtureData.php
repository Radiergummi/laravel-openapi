<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Core\Attributes\RequestField;
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
            format: 'uri',
            example: 'https://hooks.example.com/projects',
        )]
        public ?string $callbackUrl = null,
        public int $limit = 25,
    ) {}
}
