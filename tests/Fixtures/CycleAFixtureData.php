<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Spatie\LaravelData\Data;

/**
 * Fixture for indirect cycle detection (A → B → A).
 *
 * @see CycleBFixtureData
 */
final class CycleAFixtureData extends Data
{
    public function __construct(
        public string $label,
        public ?CycleBFixtureData $b = null,
    ) {}
}
