<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Plugins\Core\RouteFilters\SkipPulseRoutes;

uses()->group('routing', 'openapi');

function makePulseRoute(string $uri, ?string $domain = null): Route
{
    $route = new Route(['GET'], $uri, static fn() => null);

    if ($domain !== null) {
        $route->domain($domain);
    }

    return $route;
}

it('skips routes whose URI starts with the configured Pulse path', function (): void {
    $filter = new SkipPulseRoutes(pulsePath: 'pulse', pulseDomain: null);

    expect($filter->shouldSkip(makePulseRoute('pulse')))->toBeTrue()
        ->and($filter->shouldSkip(makePulseRoute('pulse/some-card')))->toBeTrue()
        ->and($filter->shouldSkip(makePulseRoute('flights/index')))->toBeFalse();
});

it('skips routes that match the configured Pulse domain', function (): void {
    $filter = new SkipPulseRoutes(pulsePath: 'admin', pulseDomain: 'pulse.example.test');

    expect($filter->shouldSkip(makePulseRoute('flights', 'pulse.example.test')))->toBeTrue()
        ->and($filter->shouldSkip(makePulseRoute('flights', 'api.example.test')))->toBeFalse();
});

it('tolerates Pulse being absent by leaving regular routes alone', function (): void {
    $filter = new SkipPulseRoutes(pulsePath: '', pulseDomain: null);

    expect($filter->shouldSkip(makePulseRoute('flights')))->toBeFalse()
        ->and($filter->shouldSkip(makePulseRoute('bookings/show')))->toBeFalse();
});
