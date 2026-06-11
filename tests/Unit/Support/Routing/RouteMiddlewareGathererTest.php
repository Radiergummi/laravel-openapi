<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route as RouteFacade;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\Routing\ConstructorMiddlewareScanner;
use Radiergummi\OpenApi\Support\Routing\RouteMiddlewareGatherer;
use Radiergummi\OpenApi\Tests\Fixtures\ConstructorMiddleware\ConstructorMiddlewareChildController;
use Radiergummi\OpenApi\Tests\Fixtures\ConstructorMiddleware\ConstructorMiddlewareFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\ConstructorMiddleware\StaticMiddlewareFixtureController;

uses()->group('openapi');

function middlewareGatherer(?Psr\Log\AbstractLogger $logger = null): RouteMiddlewareGatherer
{
    return new RouteMiddlewareGatherer(
        scanner: new ConstructorMiddlewareScanner(new MethodBodyScanner()),
        logger: $logger ?? recordingLogger(),
    );
}

it('passes the runtime gather through untouched for an instantiable controller', function (): void {
    $route = RouteFacade::get('/static-middleware', [StaticMiddlewareFixtureController::class, 'index']);
    $logger = recordingLogger();

    expect(middlewareGatherer($logger)->middlewareFor($route))->toBe(['auth:sanctum'])
        ->and($logger->records)->toBe([]);
});

it('falls back to route-declared middleware plus the constructor scan when instantiation throws', function (): void {
    $route = RouteFacade::get('/constructor-middleware', [ConstructorMiddlewareFixtureController::class, 'index'])
        ->middleware('api');

    $middleware = middlewareGatherer()->middlewareFor($route);

    expect($middleware)->toContain('api')
        ->and($middleware)->toContain('auth:sanctum')
        ->and($middleware)->toContain('verified')
        ->and($middleware)->not->toContain('throttle:exports');
});

it('deduplicates a name declared both on the route and in the constructor', function (): void {
    $route = RouteFacade::get('/constructor-middleware', [ConstructorMiddlewareFixtureController::class, 'index'])
        ->middleware('auth:sanctum');

    $middleware = middlewareGatherer()->middlewareFor($route);

    expect(array_keys($middleware, 'auth:sanctum', true))->toHaveCount(1);
});

it('caches the fallback per route instead of re-reading the poisoned runtime cache', function (): void {
    $route = RouteFacade::get('/constructor-middleware', [ConstructorMiddlewareFixtureController::class, 'index']);
    $gatherer = middlewareGatherer();

    $first = $gatherer->middlewareFor($route);

    // After the throw, the route's own gatherMiddleware() silently returns [] — the
    // gatherer must keep returning the merged fallback.
    expect($route->gatherMiddleware())->toBe([])
        ->and($gatherer->middlewareFor($route))->toBe($first)
        ->and($first)->toContain('auth:sanctum');
});

it('reads an inherited base-controller constructor through the declaring class', function (): void {
    $route = RouteFacade::get('/child-middleware', [ConstructorMiddlewareChildController::class, 'index']);

    expect(middlewareGatherer()->middlewareFor($route))->toBe(['auth:api']);
});

it('notices the degradation once per controller, including unreadable and conditional registrations', function (): void {
    $indexRoute = RouteFacade::get('/constructor-middleware', [ConstructorMiddlewareFixtureController::class, 'index']);
    $storeRoute = RouteFacade::post('/constructor-middleware', [ConstructorMiddlewareFixtureController::class, 'store']);

    $logger = recordingLogger();
    $gatherer = middlewareGatherer($logger);
    $gatherer->middlewareFor($indexRoute);
    $gatherer->middlewareFor($storeRoute);

    $messages = array_column($logger->records, 'message');

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toContain('could not be instantiated')
        ->and($messages[1])->toContain('no statically readable name or scope')
        ->and($messages[2])->toContain('conditionally applied');
});
