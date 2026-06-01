<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Plugins\Core\RouteFilters\SkipSelfRoutes;

uses()->group('routing', 'openapi');

function makeNamedRoute(string $uri, ?string $name = null): Route
{
    $route = new Route(['GET'], $uri, static fn() => null);

    if ($name !== null) {
        $route->name($name);
    }

    return $route;
}

it('skips routes whose name starts with the openapi. prefix', function (): void {
    $filter = new SkipSelfRoutes();

    expect($filter->shouldSkip(makeNamedRoute('api/openapi.yaml', 'openapi.spec')))->toBeTrue()
        ->and($filter->shouldSkip(makeNamedRoute('api/docs', 'openapi.playground')))->toBeTrue()
        ->and($filter->shouldSkip(makeNamedRoute('api/openapi-internal.yaml', 'openapi.spec.internal')))->toBeTrue()
        ->and($filter->shouldSkip(makeNamedRoute('api/docs/internal', 'openapi.playground.internal')))->toBeTrue();
});

it('lets through routes named with an unrelated prefix', function (): void {
    $filter = new SkipSelfRoutes();

    expect($filter->shouldSkip(makeNamedRoute('flights', 'flights.index')))->toBeFalse()
        ->and($filter->shouldSkip(makeNamedRoute('openapi-fan-page', 'marketing.openapi')))->toBeFalse();
});

it('lets through routes whose name is the literal "openapi" without trailing dot', function (): void {
    // A consumer route named "openapi" (no trailing dot) is not a library route.
    $filter = new SkipSelfRoutes();

    expect($filter->shouldSkip(makeNamedRoute('openapi', 'openapi')))->toBeFalse();
});

it('lets through unnamed routes', function (): void {
    $filter = new SkipSelfRoutes();

    expect($filter->shouldSkip(makeNamedRoute('admin/dashboard')))->toBeFalse();
});
