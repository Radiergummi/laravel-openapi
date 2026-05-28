<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Routing;

use Illuminate\Routing\Route;

interface RouteFilter
{
    public function shouldSkip(Route $route): bool;
}
