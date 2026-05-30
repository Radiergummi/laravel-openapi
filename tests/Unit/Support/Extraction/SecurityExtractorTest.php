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
use Radiergummi\OpenApi\Support\Extraction\SecurityExtractor;

uses()->group('openapi');

// Covers the public `openapi.security_schemes` and `openapi.security_default_scheme`
// config surfaces. Per-route middleware extraction is exercised end-to-end by
// AuthoringAttributesTest.

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

    // The "Passport-not-available" branch needs a router that reports Passport's
    // named routes as absent; only this one case still uses a Router mock because
    // Testbench always registers Passport in the test environment.
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

it('does not crash on a route carrying closure middleware', function (): void {
    // Closure middleware reaches gatherMiddleware() via controller middleware
    // (and is not cast to string the way Route::middleware() casts its args), so
    // inject it through the action array to reproduce the real shape.
    $route = new Route(['GET'], '/closure-mw', ['uses' => static fn() => null]);
    $route->action['middleware'] = [static fn($request, $next) => $next($request), 'auth:api'];

    $extractor = new SecurityExtractor(router: app('router'));

    // The closure middleware must be skipped; the string middleware still drives
    // the requirement. Previously this threw a TypeError on the closure key.
    expect($extractor->forRoute($route))->toBeArray();
});
