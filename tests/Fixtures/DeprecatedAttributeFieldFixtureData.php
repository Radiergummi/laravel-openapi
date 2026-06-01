<?php

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
