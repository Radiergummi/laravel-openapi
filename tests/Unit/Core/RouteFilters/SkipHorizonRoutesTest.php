<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Plugins\Core\RouteFilters\SkipHorizonRoutes;

uses()->group('routing', 'openapi');

function makeHorizonRoute(string $uri, ?string $domain = null): Route
{
    $route = new Route(['GET'], $uri, static fn() => null);

    if ($domain !== null) {
        $route->domain($domain);
    }

    return $route;
}

it('skips routes whose URI starts with the configured Horizon path', function (): void {
    $filter = new SkipHorizonRoutes(horizonPath: 'horizon', horizonDomain: null);

    expect($filter->shouldSkip(makeHorizonRoute('horizon/api/stats')))
        ->toBeTrue()
        ->and($filter->shouldSkip(makeHorizonRoute('horizon')))->toBeTrue()
        ->and($filter->shouldSkip(makeHorizonRoute('flights/index')))->toBeFalse();
});

it('skips routes that match the configured Horizon domain', function (): void {
    $filter = new SkipHorizonRoutes(horizonPath: 'admin', horizonDomain: 'horizon.example.test');

    expect($filter->shouldSkip(makeHorizonRoute('flights', 'horizon.example.test')))
        ->toBeTrue()
        ->and($filter->shouldSkip(makeHorizonRoute('flights', 'api.example.test')))->toBeFalse();
});

it('tolerates Horizon being absent by leaving regular routes alone', function (): void {
    $filter = new SkipHorizonRoutes(horizonPath: '', horizonDomain: null);

    expect($filter->shouldSkip(makeHorizonRoute('flights')))
        ->toBeFalse()
        ->and($filter->shouldSkip(makeHorizonRoute('bookings/show')))->toBeFalse();
});
