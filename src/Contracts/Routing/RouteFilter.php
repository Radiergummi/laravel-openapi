<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Routing;

use Illuminate\Routing\Route;

interface RouteFilter
{
    /**
     * Returns true when the given route should be excluded from spec generation.
     */
    public function shouldSkip(Route $route): bool;
}
