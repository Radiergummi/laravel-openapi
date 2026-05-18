<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Spatie\LaravelData\Data;

/**
 * Fixture for indirect cycle detection (A → B → A).
 *
 * @see CycleAFixtureData
 */
final class CycleBFixtureData extends Data
{
    public function __construct(
        public string $tag,
        public ?CycleAFixtureData $a = null,
    ) {}
}
