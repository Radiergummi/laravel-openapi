<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\RouteFilters\SkipIgnitionRoutes;

uses()->group('routing', 'openapi');

function makeIgnitionRoute(string $uri): Route
{
    return new Route(['GET'], $uri, static fn() => null);
}

it('skips routes whose URI starts with the configured Ignition housekeeping prefix', function (): void {
    $filter = new SkipIgnitionRoutes(ignitionPath: '_ignition');

    expect($filter->shouldSkip(makeIgnitionRoute('_ignition/health-check')))->toBeTrue()
        ->and($filter->shouldSkip(makeIgnitionRoute('_ignition')))->toBeTrue()
        ->and($filter->shouldSkip(makeIgnitionRoute('flights/index')))->toBeFalse();
});

it('tolerates Ignition being absent (empty prefix matches nothing)', function (): void {
    $filter = new SkipIgnitionRoutes(ignitionPath: '');

    expect($filter->shouldSkip(makeIgnitionRoute('flights')))->toBeFalse()
        ->and($filter->shouldSkip(makeIgnitionRoute('')))->toBeFalse()
        ->and($filter->shouldSkip(makeIgnitionRoute('_ignition/health-check')))->toBeFalse();
});
