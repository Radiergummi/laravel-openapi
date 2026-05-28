<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Attributes\Deprecated;
use Spatie\LaravelData\Data;

/**
 * Fixture Data class for OAPI-043: deprecated property detection via `#[Deprecated]`.
 */
final class DeprecatedAttributeFieldFixtureData extends Data
{
    public function __construct(
        public string $active,
        #[Deprecated(reason: 'Use active instead.')]
        public string $legacy = 'old',
    ) {}
}
