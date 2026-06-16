<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\RouteFilters;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Routing\Route;
use Override;
use Radiergummi\OpenApi\Contracts\Routing\RouteFilter;

use function in_array;
use function ltrim;

/**
 * Excludes Laravel's broadcasting channel-authorization endpoints (`broadcasting/auth` and
 * `broadcasting/user-auth`) from the spec. These are SDK internals, not documented API routes.
 * The URIs are framework literals, so no config key exists.
 */
#[Scoped]
final readonly class SkipBroadcastingRoutes implements RouteFilter
{
    private const array BROADCASTING_URIS = ['broadcasting/auth', 'broadcasting/user-auth'];

    #[Override]
    public function shouldSkip(Route $route): bool
    {
        return in_array(ltrim($route->uri(), '/'), self::BROADCASTING_URIS, true);
    }
}
