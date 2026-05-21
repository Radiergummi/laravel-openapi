<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Core\Routing\Fixtures;

/**
 * Fixture controller for {@see RouteIntrospector} unit tests.
 */
final class SimpleController
{
    /**
     * List things.
     *
     * Longer description for docblock parsing.
     */
    public function index(): array
    {
        return [];
    }
}
