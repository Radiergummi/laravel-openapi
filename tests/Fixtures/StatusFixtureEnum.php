<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

/**
 * Fixture backed enum for OAPI-034: per-case PHPDoc description surfacing.
 */
enum StatusFixtureEnum: string
{
    /** Active and visible to all users. */
    case Active = 'active';

    /** Archived and hidden from normal views. */
    case Archived = 'archived';

    /** Draft that has not been published yet. */
    case Draft = 'draft';
}
