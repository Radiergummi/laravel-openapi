<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Attributes\Description;
use Radiergummi\OpenApi\Attributes\Summary;
use Spatie\LaravelData\Data;

#[Summary('Fixture Title')]
#[Description('Fixture data class for schema-level title/description.')]
final class SchemaTitleDescriptionFixtureData extends Data
{
    public function __construct(
        public string $name,
    ) {}
}
