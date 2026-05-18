<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Core\Attributes\IgnoreLint;
use Spatie\LaravelData\Data;

/**
 * Data fixture with class- and property-scoped {@see IgnoreLint} attributes,
 * reached by SuppressionCollector through a controller parameter.
 */
#[IgnoreLint('field.no-effect', reason: 'class scope')]
final class SuppressedFixtureData extends Data
{
    public function __construct(
        #[IgnoreLint('field.invalid-format', reason: 'property scope')]
        public string $name = '',
        public string $other = '',
    ) {}
}
