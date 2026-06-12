<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Radiergummi\OpenApi\Attributes\PublicEndpoint;

/**
 * Applies `auth:sanctum` via the static `HasMiddleware` form — controller-wired, not
 * route-declared. Exercises the lint readers reading the gathered (controller-aware) middleware
 * list rather than only `Route::middleware()` (#260).
 */
class ControllerMiddlewareAuthController implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('auth:sanctum')];
    }

    /** Authed via controller middleware, no #[PublicEndpoint] — `operation.security-missing` must fire. */
    public function protectedAction(): void {}

    /** Public + controller-wired auth — `publicendpoint.contradicts-middleware` must fire. */
    #[PublicEndpoint]
    public function publicAction(): void {}
}
