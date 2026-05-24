<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Routing\Filters\SkipNovaRoutes;

uses()->group('routing', 'openapi');

function makeNovaRoute(string $uri, ?string $domain = null): Route
{
    $route = new Route(['GET'], $uri, static fn() => null);

    if ($domain !== null) {
        $route->domain($domain);
    }

    return $route;
}

it('skips routes whose URI starts with the configured Nova path', function (): void {
    $filter = new SkipNovaRoutes(novaPath: 'nova', novaDomain: null);

    expect($filter->shouldSkip(makeNovaRoute('nova/dashboards/main')))->toBeTrue()
        ->and($filter->shouldSkip(makeNovaRoute('nova')))->toBeTrue()
        ->and($filter->shouldSkip(makeNovaRoute('flights/index')))->toBeFalse();
});

it('always skips internal nova- prefixed routes regardless of configured path', function (): void {
    $filter = new SkipNovaRoutes(novaPath: '', novaDomain: null);

    expect($filter->shouldSkip(makeNovaRoute('nova-api/scripts/foo')))->toBeTrue()
        ->and($filter->shouldSkip(makeNovaRoute('nova-vendor/bar')))->toBeTrue();
});

it('skips routes that match the configured Nova domain', function (): void {
    $filter = new SkipNovaRoutes(novaPath: 'admin', novaDomain: 'nova.example.test');

    expect($filter->shouldSkip(makeNovaRoute('flights', 'nova.example.test')))->toBeTrue()
        ->and($filter->shouldSkip(makeNovaRoute('flights', 'api.example.test')))->toBeFalse();
});

it('tolerates Nova being absent by leaving regular routes alone', function (): void {
    $filter = new SkipNovaRoutes(novaPath: '', novaDomain: null);

    expect($filter->shouldSkip(makeNovaRoute('flights')))->toBeFalse()
        ->and($filter->shouldSkip(makeNovaRoute('bookings/show')))->toBeFalse();
});
