<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Plugins\Core\RouteFilters\SkipBroadcastingRoutes;

uses()->group('routing', 'openapi');

beforeEach(function (): void {
    $this->filter = new SkipBroadcastingRoutes();
});

function makeBroadcastingRoute(string $uri): Route
{
    return new Route(['GET', 'POST'], $uri, static fn() => null);
}

it('skips the broadcasting channel-authorization endpoints', function (): void {
    expect($this->filter->shouldSkip(makeBroadcastingRoute('broadcasting/auth')))->toBeTrue()
        ->and($this->filter->shouldSkip(makeBroadcastingRoute('broadcasting/user-auth')))->toBeTrue()
        ->and($this->filter->shouldSkip(makeBroadcastingRoute('/broadcasting/auth')))->toBeTrue();
});

it('keeps other routes, including unrelated broadcasting-prefixed URIs', function (): void {
    expect($this->filter->shouldSkip(makeBroadcastingRoute('broadcasting/channels')))->toBeFalse()
        ->and($this->filter->shouldSkip(makeBroadcastingRoute('flights/index')))->toBeFalse()
        ->and($this->filter->shouldSkip(makeBroadcastingRoute('auth')))->toBeFalse();
});
