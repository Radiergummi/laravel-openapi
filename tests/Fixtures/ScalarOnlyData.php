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

/** Flat Data class with only scalar properties — used by DataSyntheticPayloadBuilderTest. */
final class ScalarOnlyData extends Data
{
    public function __construct(
        public string $name,
        public int $count,
        public ?float $score = null,
    ) {}
}
