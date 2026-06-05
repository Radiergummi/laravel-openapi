<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\RouteFilters;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Contracts\Routing\RouteFilter;

use function in_array;
use function ltrim;

/**
 * Excludes the broadcasting channel-authorization endpoints from the generated OpenAPI spec.
 *
 * `Broadcast::routes()` / `Broadcast::userRoutes()` register the fixed `broadcasting/auth` and
 * `broadcasting/user-auth` endpoints (GET|POST, unnamed). They authorize private/presence channels
 * for the client SDK (Reverb / Pusher / Echo) and are not part of an app's documented API.
 *
 * Unlike the dashboard filters there is no `*.path` config key — the URIs are literals baked into
 * the framework — so the class takes no constructor arguments and matches them directly. Tolerates
 * broadcasting being unused: an app that never calls `Broadcast::routes()` has no such routes to skip.
 */
#[Scoped]
final readonly class SkipBroadcastingRoutes implements RouteFilter
{
    private const array BROADCASTING_URIS = ['broadcasting/auth', 'broadcasting/user-auth'];

    public function shouldSkip(Route $route): bool
    {
        return in_array(ltrim($route->uri(), '/'), self::BROADCASTING_URIS, true);
    }
}
