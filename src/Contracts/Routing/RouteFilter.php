<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Routing;

use Illuminate\Routing\Route;

interface RouteFilter
{
    public function shouldSkip(Route $route): bool;
}
