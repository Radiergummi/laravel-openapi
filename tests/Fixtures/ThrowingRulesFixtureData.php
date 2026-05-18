<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use RuntimeException;
use Spatie\LaravelData\Data;

/**
 * Fixture whose {@see rules()} method always throws.
 *
 * Used to verify that {@see \Radiergummi\OpenApi\Plugins\SpatieData\SchemaFromDataClass}
 * falls back gracefully rather than aborting the entire generation run when rule
 * extraction fails for a single Data class.
 */
final class ThrowingRulesFixtureData extends Data
{
    public function __construct(
        public string $name,
    ) {}

    /** @throws RuntimeException always */
    public static function rules(): array
    {
        throw new RuntimeException('boom');
    }
}
