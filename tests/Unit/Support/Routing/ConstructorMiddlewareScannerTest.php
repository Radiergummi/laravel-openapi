<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\Routing\ConstructorMiddlewareScanner;
use Radiergummi\OpenApi\Tests\Fixtures\ConstructorMiddleware\ConstructorMiddlewareChildController;
use Radiergummi\OpenApi\Tests\Fixtures\ConstructorMiddleware\ConstructorMiddlewareFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\ConstructorMiddleware\StaticMiddlewareFixtureController;

uses()->group('openapi');

function scanConstructorMiddleware(string $controller): Radiergummi\OpenApi\Support\Routing\ConstructorMiddlewareScan
{
    return new ConstructorMiddlewareScanner(new MethodBodyScanner())
        ->scan(new ReflectionClass($controller));
}

// region Whitelist matching

it('reads an unscoped literal registration for every action', function (): void {
    $scan = scanConstructorMiddleware(ConstructorMiddlewareFixtureController::class);

    expect($scan->middlewareForAction('index'))->toContain('auth:sanctum')
        ->and($scan->middlewareForAction('store'))->toContain('auth:sanctum')
        ->and($scan->middlewareForAction('health'))->toContain('auth:sanctum');
});

it('honours fluent only() scoping with an array literal', function (): void {
    $scan = scanConstructorMiddleware(ConstructorMiddlewareFixtureController::class);

    expect($scan->middlewareForAction('index'))->toContain('verified')
        ->and($scan->middlewareForAction('store'))->not->toContain('verified');
});

it('honours fluent except() scoping with a bare string argument', function (): void {
    $scan = scanConstructorMiddleware(ConstructorMiddlewareFixtureController::class);

    expect($scan->middlewareForAction('index'))->not->toContain('throttle:exports')
        ->and($scan->middlewareForAction('store'))->toContain('throttle:exports');
});

it('reads the options-array scoping form and the array middleware argument', function (): void {
    $scan = scanConstructorMiddleware(ConstructorMiddlewareFixtureController::class);

    expect($scan->middlewareForAction('store'))->toContain('signed.params')
        ->and($scan->middlewareForAction('index'))->not->toContain('signed.params');
});

// endregion

// region Degradation

it('refuses a non-literal middleware name and reports it as unreadable', function (): void {
    $scan = scanConstructorMiddleware(ConstructorMiddlewareFixtureController::class);

    $allNames = [
        ...$scan->middlewareForAction('index'),
        ...$scan->middlewareForAction('store'),
        ...$scan->middlewareForAction('health'),
    ];

    expect($allNames)->not->toContain('computed')
        ->and($scan->unreadableCallDetected)->toBeTrue();
});

it('refuses a conditionally applied registration and reports it as conditional', function (): void {
    $scan = scanConstructorMiddleware(ConstructorMiddlewareFixtureController::class);

    expect($scan->middlewareForAction('index'))->not->toContain('debugbar')
        ->and($scan->conditionalCallDetected)->toBeTrue();
});

it('ignores middleware() calls on a receiver other than the literal $this', function (): void {
    $scan = scanConstructorMiddleware(ConstructorMiddlewareFixtureController::class);

    expect($scan->middlewareForAction('index'))->not->toContain('aliased-receiver');
});

// endregion

// region Inheritance and absence

it('scans an inherited base-controller constructor through the declaring class', function (): void {
    $scan = scanConstructorMiddleware(ConstructorMiddlewareChildController::class);

    expect($scan->middlewareForAction('index'))->toBe(['auth:api'])
        ->and($scan->unreadableCallDetected)->toBeFalse()
        ->and($scan->conditionalCallDetected)->toBeFalse();
});

it('returns an empty scan for a controller without a constructor', function (): void {
    $scan = scanConstructorMiddleware(StaticMiddlewareFixtureController::class);

    expect($scan->entries)->toBe([])
        ->and($scan->middlewareForAction('index'))->toBe([])
        ->and($scan->unreadableCallDetected)->toBeFalse()
        ->and($scan->conditionalCallDetected)->toBeFalse();
});

// endregion
