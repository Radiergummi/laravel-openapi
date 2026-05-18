<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

/** Minimal unit (non-backed) enum used by JsonSchemaFromTypeTest. */
enum UnitFixtureEnum
{
    case Alpha;
    case Beta;
}
