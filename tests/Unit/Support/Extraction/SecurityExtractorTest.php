<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Radiergummi\OpenApi\Support\Extraction\SecurityExtractor;
use Radiergummi\OpenApi\Support\Routing\RouteMiddlewareGatherer;

uses()->group('openapi');

function securityExtractor(Router $router): SecurityExtractor
{
    return new SecurityExtractor(
        router: $router,
        middlewareGatherer: app(RouteMiddlewareGatherer::class),
    );
}

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

    $extractor = securityExtractor(app('router'));
    $names     = array_map(static fn($s) => $s->securityScheme, $extractor->buildSchemes());

    expect($names)->toContain('bearer');
});

it('merges config-declared schemes with Passport-derived ones', function (): void {
    config()->set('openapi.security_schemes', [
        'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
    ]);

    $extractor = securityExtractor(app('router'));
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

    $extractor = securityExtractor(app('router'));

    $byName = [];

    foreach ($extractor->buildSchemes() as $scheme) {
        $byName[$scheme->securityScheme] = $scheme;
    }

    expect($byName['oauth2']->type)->toBe('apiKey');
});

it('auto-derives a sanctum bearer scheme when an auth:sanctum route exists', function (): void {
    app('router')->get('/protected', static fn() => null)->middleware('auth:sanctum');

    $extractor = securityExtractor(app('router'));

    $byName = [];

    foreach ($extractor->buildSchemes() as $scheme) {
        $byName[$scheme->securityScheme] = $scheme;
    }

    expect($byName)->toHaveKey('sanctum')
        ->and($byName['sanctum']->type)->toBe('http')
        ->and($byName['sanctum']->scheme)->toBe('bearer');
});

it('does not register a sanctum scheme when no route uses auth:sanctum', function (): void {
    // Token-based detection guards against over-registration: Passport is the only
    // auto-derived source here, and no route carries auth:sanctum.
    $extractor = securityExtractor(app('router'));

    $names = array_map(static fn($s) => $s->securityScheme, $extractor->buildSchemes());

    expect($names)->not->toContain('sanctum');
});

it('emits a sanctum per-operation requirement for an auth:sanctum route when Passport is absent', function (): void {
    config()->set('openapi.security_schemes', []);
    config()->set('openapi.security_default_scheme', null);

    $route                       = new Route(['GET'], '/protected', ['uses' => static fn() => null]);
    $route->action['middleware'] = ['auth:sanctum'];

    $collection = new RouteCollection();
    $collection->add($route);

    // Passport absent so the default resolves to Sanctum alone; the route collection
    // must report the auth:sanctum route so sanctumInUse() detects it.
    $router = Mockery::mock(Router::class);
    $router->allows('has')->andReturn(false)->byDefault();
    $router->allows('getMiddlewareGroups')->andReturn([])->byDefault();
    $router->allows('getRoutes')->andReturn($collection)->byDefault();

    $extractor = securityExtractor($router);

    expect($extractor->forRoute($route))->toBe([['sanctum' => []]]);
});

it('targets the explicit scheme name when requirementForScopes is called with $scheme', function (): void {
    $extractor = securityExtractor(app('router'));

    expect($extractor->requirementForScopes(['read'], scheme: 'bearer'))
        ->toBe([['bearer' => ['read']]]);
});

it('uses openapi.security_default_scheme (string) as the default when set', function (): void {
    config()->set('openapi.security_schemes', [
        'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
    ]);
    config()->set('openapi.security_default_scheme', 'bearer');

    $extractor = securityExtractor(app('router'));

    expect($extractor->requirementForScopes(['read']))
        ->toBe([['bearer' => ['read']]]);
});

it('uses openapi.security_default_scheme (list) to emit multiple OR-alternatives', function (): void {
    config()->set('openapi.security_schemes', [
        'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
        'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'],
    ]);
    config()->set('openapi.security_default_scheme', ['bearer', 'apiKey']);

    $extractor = securityExtractor(app('router'));

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
    $router->allows('getMiddlewareGroups')->andReturn([])->byDefault();
    $router->allows('getRoutes')->andReturn(new RouteCollection())->byDefault();

    $extractor = securityExtractor($router);

    expect($extractor->requirementForScopes(['read']))
        ->toBe([['bearer' => ['read']]]);
});

it('preserves the Passport oauth2 pair as the default when default is unset and Passport is installed', function (): void {
    config()->set('openapi.security_default_scheme', null);

    $extractor = securityExtractor(app('router'));

    expect($extractor->requirementForScopes(['read']))
        ->toBe([
            ['oauth2' => ['read']],
            ['oauth2ClientCredentials' => ['read']],
        ]);
});

it('omits security for an auth route when no scheme is derivable, instead of an empty (public) requirement', function (): void {
    config()->set('openapi.security_schemes', []);
    config()->set('openapi.security_default_scheme', null);

    // Passport absent + no config schemes + a non-Sanctum guard => no scheme can be
    // derived for the auth:* route. `auth:web` (not auth:sanctum, which is now derivable)
    // and an empty route collection keep sanctumInUse() false. The operation must omit
    // `security` (null) rather than assert `[]`, which OpenAPI reads as "explicitly public".
    $router = Mockery::mock(Router::class);
    $router->allows('has')->andReturn(false)->byDefault();
    $router->allows('getMiddlewareGroups')->andReturn([])->byDefault();
    $router->allows('getRoutes')->andReturn(new RouteCollection())->byDefault();

    $route                        = new Route(['GET'], '/protected', ['uses' => static fn() => null]);
    $route->action['middleware']  = ['auth:web'];

    $extractor = securityExtractor($router);

    expect($extractor->forRoute($route))->toBeNull();
});

it('emits an explicit empty (public) requirement for a route with no auth or scope middleware', function (): void {
    $route = new Route(['GET'], '/open', ['uses' => static fn() => null]);

    $extractor = securityExtractor(app('router'));

    expect($extractor->forRoute($route))->toBe([]);
});

// #33 — Sanctum abilities:/ability: middleware feeds the scope list like Passport scopes.

it('lists abilities:read,write as scopes on the security requirement', function (): void {
    config()->set('openapi.security_schemes', []);
    config()->set('openapi.security_default_scheme', null);

    $route                       = new Route(['GET'], '/protected', ['uses' => static fn() => null]);
    $route->action['middleware'] = ['auth:sanctum', 'abilities:read,write'];

    $collection = new RouteCollection();
    $collection->add($route);

    $router = Mockery::mock(Router::class);
    $router->allows('has')->andReturn(false)->byDefault();
    $router->allows('getMiddlewareGroups')->andReturn([])->byDefault();
    $router->allows('getRoutes')->andReturn($collection)->byDefault();

    $extractor = securityExtractor($router);

    expect($extractor->forRoute($route))->toBe([['sanctum' => ['read', 'write']]]);
});

it('lists ability:admin as a scope on the security requirement', function (): void {
    config()->set('openapi.security_schemes', []);
    config()->set('openapi.security_default_scheme', null);

    $route                       = new Route(['GET'], '/protected', ['uses' => static fn() => null]);
    $route->action['middleware'] = ['auth:sanctum', 'ability:admin'];

    $collection = new RouteCollection();
    $collection->add($route);

    $router = Mockery::mock(Router::class);
    $router->allows('has')->andReturn(false)->byDefault();
    $router->allows('getMiddlewareGroups')->andReturn([])->byDefault();
    $router->allows('getRoutes')->andReturn($collection)->byDefault();

    $extractor = securityExtractor($router);

    expect($extractor->forRoute($route))->toBe([['sanctum' => ['admin']]]);
});

it('maps ability: (any-of) to OR-alternative requirements, one per ability', function (): void {
    config()->set('openapi.security_schemes', []);
    config()->set('openapi.security_default_scheme', null);

    // Sanctum's `ability` middleware passes when the token has ANY one of the listed abilities,
    // so each becomes its own OR-alternative requirement rather than a single all-of list.
    $route                       = new Route(['GET'], '/protected', ['uses' => static fn() => null]);
    $route->action['middleware'] = ['auth:sanctum', 'ability:read,write'];

    $collection = new RouteCollection();
    $collection->add($route);

    $router = Mockery::mock(Router::class);
    $router->allows('has')->andReturn(false)->byDefault();
    $router->allows('getMiddlewareGroups')->andReturn([])->byDefault();
    $router->allows('getRoutes')->andReturn($collection)->byDefault();

    $extractor = securityExtractor($router);

    expect($extractor->forRoute($route))->toBe([
        ['sanctum' => ['read']],
        ['sanctum' => ['write']],
    ]);
});

it('carries the all-of abilities onto each any-of alternative when both are present', function (): void {
    config()->set('openapi.security_schemes', []);
    config()->set('openapi.security_default_scheme', null);

    // abilities:base (all-of) must hold for every alternative; ability:x,y (any-of) splits.
    $route                       = new Route(['GET'], '/protected', ['uses' => static fn() => null]);
    $route->action['middleware'] = ['auth:sanctum', 'abilities:base', 'ability:x,y'];

    $collection = new RouteCollection();
    $collection->add($route);

    $router = Mockery::mock(Router::class);
    $router->allows('has')->andReturn(false)->byDefault();
    $router->allows('getMiddlewareGroups')->andReturn([])->byDefault();
    $router->allows('getRoutes')->andReturn($collection)->byDefault();

    $extractor = securityExtractor($router);

    expect($extractor->forRoute($route))->toBe([
        ['sanctum' => ['base', 'x']],
        ['sanctum' => ['base', 'y']],
    ]);
});

it('deduplicates repeated abilities like the Passport scope path', function (): void {
    config()->set('openapi.security_schemes', []);
    config()->set('openapi.security_default_scheme', null);

    $route                       = new Route(['GET'], '/protected', ['uses' => static fn() => null]);
    $route->action['middleware'] = ['auth:sanctum', 'abilities:read,read,write'];

    $collection = new RouteCollection();
    $collection->add($route);

    $router = Mockery::mock(Router::class);
    $router->allows('has')->andReturn(false)->byDefault();
    $router->allows('getMiddlewareGroups')->andReturn([])->byDefault();
    $router->allows('getRoutes')->andReturn($collection)->byDefault();

    $extractor = securityExtractor($router);

    expect($extractor->forRoute($route))->toBe([['sanctum' => ['read', 'write']]]);
});

// #34 — openapi.security_middleware_map points custom guard middleware at a declared scheme.

it('emits the mapped scheme for a route carrying a configured custom guard middleware', function (): void {
    config()->set('openapi.security_schemes', [
        'partner' => ['type' => 'http', 'scheme' => 'bearer'],
    ]);
    config()->set('openapi.security_default_scheme', null);
    config()->set('openapi.security_middleware_map', ['partner-guard' => 'partner']);

    $route                       = new Route(['GET'], '/partner', ['uses' => static fn() => null]);
    $route->action['middleware'] = ['partner-guard'];

    $router = Mockery::mock(Router::class);
    $router->allows('has')->andReturn(false)->byDefault();
    $router->allows('getMiddlewareGroups')->andReturn([])->byDefault();
    $router->allows('getRoutes')->andReturn(new RouteCollection())->byDefault();

    $extractor = securityExtractor($router);

    expect($extractor->forRoute($route))->toBe([['partner' => []]]);
});

it('leaves a route with no mapped middleware as a public requirement', function (): void {
    config()->set('openapi.security_middleware_map', ['partner-guard' => 'partner']);

    $route = new Route(['GET'], '/open', ['uses' => static fn() => null]);

    $extractor = securityExtractor(app('router'));

    expect($extractor->forRoute($route))->toBe([]);
});

it('lets a mapped scheme take precedence over the auto-derived default, carrying the route scopes', function (): void {
    config()->set('openapi.security_schemes', [
        'partner' => ['type' => 'http', 'scheme' => 'bearer'],
    ]);
    config()->set('openapi.security_default_scheme', null);
    config()->set('openapi.security_middleware_map', ['partner-guard' => 'partner']);

    // The route also carries auth:sanctum (which would otherwise resolve the default to sanctum),
    // but the explicit map entry fully describes this route: only the mapped partner scheme is
    // emitted, carrying the abilities-derived scopes. The sanctum default is not appended.
    $route                       = new Route(['GET'], '/protected', ['uses' => static fn() => null]);
    $route->action['middleware'] = ['partner-guard', 'auth:sanctum', 'abilities:read'];

    $collection = new RouteCollection();
    $collection->add($route);

    $router = Mockery::mock(Router::class);
    $router->allows('has')->andReturn(false)->byDefault();
    $router->allows('getMiddlewareGroups')->andReturn([])->byDefault();
    $router->allows('getRoutes')->andReturn($collection)->byDefault();

    $extractor = securityExtractor($router);

    expect($extractor->forRoute($route))->toBe([['partner' => ['read']]]);
});

it('emits a single requirement for a mapped scheme whose name matches the auto-derived default', function (): void {
    config()->set('openapi.security_schemes', []);
    config()->set('openapi.security_default_scheme', null);
    config()->set('openapi.security_middleware_map', ['sanctum-guard' => 'sanctum']);

    $route                       = new Route(['GET'], '/protected', ['uses' => static fn() => null]);
    $route->action['middleware'] = ['sanctum-guard', 'auth:sanctum'];

    $collection = new RouteCollection();
    $collection->add($route);

    $router = Mockery::mock(Router::class);
    $router->allows('has')->andReturn(false)->byDefault();
    $router->allows('getMiddlewareGroups')->andReturn([])->byDefault();
    $router->allows('getRoutes')->andReturn($collection)->byDefault();

    $extractor = securityExtractor($router);

    expect($extractor->forRoute($route))->toBe([['sanctum' => []]]);
});

it('does not leak an empty-string scope from an argument-less ability token', function (): void {
    config()->set('openapi.security_schemes', []);
    config()->set('openapi.security_default_scheme', null);

    $route                       = new Route(['GET'], '/protected', ['uses' => static fn() => null]);
    $route->action['middleware'] = ['auth:sanctum', 'ability:'];

    $collection = new RouteCollection();
    $collection->add($route);

    $router = Mockery::mock(Router::class);
    $router->allows('has')->andReturn(false)->byDefault();
    $router->allows('getMiddlewareGroups')->andReturn([])->byDefault();
    $router->allows('getRoutes')->andReturn($collection)->byDefault();

    $extractor = securityExtractor($router);

    expect($extractor->forRoute($route))->toBe([['sanctum' => []]]);
});

it('does not crash on a route carrying closure middleware', function (): void {
    // Closure middleware reaches gatherMiddleware() via controller middleware
    // (and is not cast to string the way Route::middleware() casts its args), so
    // inject it through the action array to reproduce the real shape.
    $route = new Route(['GET'], '/closure-mw', ['uses' => static fn() => null]);
    $route->action['middleware'] = [static fn($request, $next) => $next($request), 'auth:api'];

    $extractor = securityExtractor(app('router'));

    // The closure middleware must be skipped; the string middleware still drives
    // the requirement. Previously this threw a TypeError on the closure key.
    // With Passport installed in Testbench and no scopes, `auth:api` yields the
    // default Passport oauth2 pair.
    expect($extractor->forRoute($route))
        ->toBe([
            ['oauth2' => []],
            ['oauth2ClientCredentials' => []],
        ]);
});

it('merges constructor middleware into the requirement when the controller cannot be instantiated', function (): void {
    $route = Illuminate\Support\Facades\Route::get(
        '/constructor-mw',
        [Radiergummi\OpenApi\Tests\Fixtures\ConstructorMiddleware\ConstructorMiddlewareFixtureController::class, 'index'],
    );

    $extractor = securityExtractor(app('router'));

    // The constructor applies `auth:sanctum`; instantiation throws on the unbound dependency,
    // so the requirement can only come from the static constructor scan.
    expect($extractor->forRoute($route))->toContain(['sanctum' => []]);
});

it('does not double-derive when route and constructor declare the same middleware', function (): void {
    $route = Illuminate\Support\Facades\Route::get(
        '/constructor-mw',
        [Radiergummi\OpenApi\Tests\Fixtures\ConstructorMiddleware\ConstructorMiddlewareFixtureController::class, 'index'],
    )->middleware('auth:sanctum');

    $extractor = securityExtractor(app('router'));
    $requirement = $extractor->forRoute($route) ?? [];

    expect(array_values(array_keys($requirement, ['sanctum' => []], true)))->toHaveCount(1);
});
