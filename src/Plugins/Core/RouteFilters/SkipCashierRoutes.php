<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\RouteFilters;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Routing\Route;
use Override;
use Radiergummi\OpenApi\Contracts\Routing\RouteFilter;

use function str_starts_with;

/**
 * Excludes Laravel Cashier routes (Stripe and Paddle) from the generated spec. Tolerates Cashier
 * being absent; the filter simply matches nothing.
 */
#[Scoped]
final readonly class SkipCashierRoutes implements RouteFilter
{
    #[Override]
    public function shouldSkip(Route $route): bool
    {
        $name = $route->getName() ?? '';

        return str_starts_with($name, 'cashier.');
    }
}
