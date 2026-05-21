<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Radiergummi\OpenApi\Core\Extractors\SecurityExtractor;

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
    // Report Passport's named routes as registered so the legacy two-scheme requirement applies.
    $router->allows('has')->andReturn(true)->byDefault();

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

// ─── Config-driven security_schemes ──────────────────────────────────────────

it('emits config-declared security schemes from openapi.security_schemes', function (): void {
    config()->set('openapi.security_schemes', [
        'bearer' => [
            'type'         => 'http',
            'scheme'       => 'bearer',
            'bearerFormat' => 'JWT',
        ],
    ]);

    $extractor = new SecurityExtractor(router: app('router'));
    $names     = array_map(static fn($s) => $s->securityScheme, $extractor->buildSchemes());

    expect($names)->toContain('bearer');
});

it('merges config-declared schemes with Passport-derived ones', function (): void {
    config()->set('openapi.security_schemes', [
        'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
    ]);

    $extractor = new SecurityExtractor(router: app('router'));
    $names     = array_map(static fn($s) => $s->securityScheme, $extractor->buildSchemes());

    expect($names)
        ->toContain('bearer')
        ->and($names)->toContain('oauth2')
        ->and($names)->toContain('oauth2ClientCredentials');
});

it('lets a config entry override a Passport-derived scheme on key collision', function (): void {
    config()->set('openapi.security_schemes', [
        'oauth2' => ['type' => 'apiKey', 'name' => 'X-API-Key', 'in' => 'header'],
    ]);

    $extractor = new SecurityExtractor(router: app('router'));

    $byName = [];

    foreach ($extractor->buildSchemes() as $scheme) {
        $byName[$scheme->securityScheme] = $scheme;
    }

    expect($byName['oauth2']->type)->toBe('apiKey');
});

it('targets the explicit scheme name when requirementForScopes is called with $scheme', function (): void {
    $extractor = new SecurityExtractor(router: app('router'));

    expect($extractor->requirementForScopes(['read'], scheme: 'bearer'))
        ->toBe([['bearer' => ['read']]]);
});

it('uses openapi.security_default_scheme (string) as the default when set', function (): void {
    config()->set('openapi.security_schemes', [
        'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
    ]);
    config()->set('openapi.security_default_scheme', 'bearer');

    $extractor = new SecurityExtractor(router: app('router'));

    expect($extractor->requirementForScopes(['read']))
        ->toBe([['bearer' => ['read']]]);
});

it('uses openapi.security_default_scheme (list) to emit multiple OR-alternatives', function (): void {
    config()->set('openapi.security_schemes', [
        'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
        'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'],
    ]);
    config()->set('openapi.security_default_scheme', ['bearer', 'apiKey']);

    $extractor = new SecurityExtractor(router: app('router'));

    expect($extractor->requirementForScopes(['read']))
        ->toBe([
            ['bearer' => ['read']],
            ['apiKey' => ['read']],
        ]);
});

it('falls back to the first config-declared scheme when default is unset and Passport is absent', function (): void {
    config()->set('openapi.security_schemes', [
        'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
        'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'],
    ]);
    config()->set('openapi.security_default_scheme', null);

    // Router reports Passport's named routes as absent — exercises the
    // "Passport-not-available" branch of defaultSchemeNames().
    $router = Mockery::mock(Router::class);
    $router->allows('has')->andReturn(false)->byDefault();

    $extractor = new SecurityExtractor(router: $router);

    expect($extractor->requirementForScopes(['read']))
        ->toBe([['bearer' => ['read']]]);
});

it('preserves the Passport oauth2 pair as the default when default is unset and Passport is installed', function (): void {
    config()->set('openapi.security_default_scheme', null);

    $extractor = new SecurityExtractor(router: app('router'));

    expect($extractor->requirementForScopes(['read']))
        ->toBe([
            ['oauth2' => ['read']],
            ['oauth2ClientCredentials' => ['read']],
        ]);
});
