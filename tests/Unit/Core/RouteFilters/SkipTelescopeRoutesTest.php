<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\RouteFilters\SkipTelescopeRoutes;

uses()->group('routing', 'openapi');

function makeTelescopeRoute(string $uri, ?string $domain = null): Route
{
    $route = new Route(['GET'], $uri, static fn() => null);

    if ($domain !== null) {
        $route->domain($domain);
    }

    return $route;
}

it('skips routes whose URI starts with the configured Telescope path', function (): void {
    $filter = new SkipTelescopeRoutes(telescopePath: 'telescope', telescopeDomain: null);

    expect($filter->shouldSkip(makeTelescopeRoute('telescope/requests')))->toBeTrue()
        ->and($filter->shouldSkip(makeTelescopeRoute('telescope')))->toBeTrue()
        ->and($filter->shouldSkip(makeTelescopeRoute('flights/index')))->toBeFalse();
});

it('skips routes that match the configured Telescope domain', function (): void {
    $filter = new SkipTelescopeRoutes(telescopePath: 'admin', telescopeDomain: 'telescope.example.test');

    expect($filter->shouldSkip(makeTelescopeRoute('flights', 'telescope.example.test')))->toBeTrue()
        ->and($filter->shouldSkip(makeTelescopeRoute('flights', 'api.example.test')))->toBeFalse();
});

it('tolerates Telescope being absent by leaving regular routes alone', function (): void {
    $filter = new SkipTelescopeRoutes(telescopePath: '', telescopeDomain: null);

    expect($filter->shouldSkip(makeTelescopeRoute('flights')))->toBeFalse()
        ->and($filter->shouldSkip(makeTelescopeRoute('bookings/show')))->toBeFalse();
});
