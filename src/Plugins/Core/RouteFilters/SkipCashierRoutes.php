<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\RouteFilters;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Contracts\Routing\RouteFilter;

use function str_starts_with;

/**
 * Excludes Laravel Cashier's own routes from the generated OpenAPI spec.
 *
 * Both Cashier drivers register their routes under the `cashier.` route-name prefix: cashier-stripe
 * (webhook receiver + the SCA payment-confirmation page) and cashier-paddle each wrap their route
 * group in `'as' => 'cashier.'`. These are vendor callbacks/pages, not part of an app's documented
 * API, so they are matched on the shared name prefix — the shape of {@see SkipPassportRoutes}.
 *
 * Tolerates Cashier being absent: with no `cashier.*` routes registered the filter matches nothing.
 * The prefix is not user-configurable, so the class takes no constructor arguments.
 */
#[Scoped]
final readonly class SkipCashierRoutes implements RouteFilter
{
    public function shouldSkip(Route $route): bool
    {
        $name = $route->getName() ?? '';

        return str_starts_with($name, 'cashier.');
    }
}
