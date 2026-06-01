<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Spatie\LaravelData\Data;

/**
 * Fixture Data class carried by {@see ActionFixture} — used to verify that the OpenAPI generator
 * walks the Action constructor to find the Data class (OAPI-010).
 */
final class ActionFixtureData extends Data
{
    public function __construct(
        public string $title,
        public ?string $body = null,
    ) {}
}
