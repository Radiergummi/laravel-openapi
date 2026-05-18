<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Extractors\SecurityExtractor;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

uses()->group('openapi');

/**
 * Build a SecurityExtractor whose Router knows the given middleware groups.
 *
 * @param array<string, string[]> $groups
 */
function makeSecurityExtractor(array $groups = []): SecurityExtractor
{
    $router = Mockery::mock(Router::class);
    $router->allows('getMiddlewareGroups')->andReturn($groups)->byDefault();

    return new SecurityExtractor(router: $router);
}

/**
 * Build a Route whose gatherMiddleware() returns the given list.
 *
 * @param string[] $middleware
 */
function routeWithMiddleware(array $middleware): Route
{
    $route = Mockery::mock(Route::class);
    $route->allows('gatherMiddleware')->andReturn($middleware);

    return $route;
}

// ─── Group expansion ──────────────────────────────────────────────────────────

it('surfaces auth from an internal-api middleware group', function (): void {
    $extractor = makeSecurityExtractor([
        'internal-api' => ['StartSession', 'auth'],
    ]);

    $route = routeWithMiddleware(['internal-api']);

    expect($extractor->forRoute($route))->not->toBeEmpty();
});

it('surfaces auth from an external-api middleware group', function (): void {
    $extractor = makeSecurityExtractor([
        'external-api' => ['api', 'auth:api'],
        'api'          => ['SubstituteBindings'],
    ]);

    $route = routeWithMiddleware(['external-api']);

    expect($extractor->forRoute($route))->not->toBeEmpty();
});

it('returns empty security for a route with only the api group and no auth', function (): void {
    $extractor = makeSecurityExtractor([
        'api' => ['SubstituteBindings'],
    ]);

    $route = routeWithMiddleware(['api']);

    expect($extractor->forRoute($route))->toBeEmpty();
});

it('extracts scope from an internal-api group combined with a scope middleware', function (): void {
    $extractor = makeSecurityExtractor([
        'internal-api' => ['StartSession', 'auth'],
    ]);

    $route = routeWithMiddleware(['internal-api', 'scope:search']);

    $security = $extractor->forRoute($route);

    expect($security)->not->toBeEmpty();

    $allScopes = [];

    foreach ($security as $requirement) {
        foreach ($requirement as $scopes) {
            $allScopes = [...$allScopes, ...$scopes];
        }
    }

    expect($allScopes)->toContain('search');
});

// ─── Real Route instances (no mock) ──────────────────────────────────────────

it('does not crash when gatherMiddleware returns an empty list', function (): void {
    $extractor = makeSecurityExtractor();
    $route     = new Route(['GET'], 'api/v0/open', []);

    expect($extractor->forRoute($route))->toBeEmpty();
});
