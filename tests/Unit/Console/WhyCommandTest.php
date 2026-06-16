<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Console;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Contracts\Routing\RouteFilter;

uses()->group('openapi');

/**
 * Test-local global filter that skips the `api/v1/flights` fixture route.
 */
final class FlightsRouteFilter implements RouteFilter
{
    public function shouldSkip(RoutingRoute $route): bool
    {
        return $route->uri() === 'api/v1/flights';
    }
}

beforeEach(function (): void {
    Route::get('api/v1/flights', fn() => 'x')->name('v1.flights.index');
    app()->forgetScopedInstances();
});

it('explains why a route is included in each spec', function (): void {
    config(['openapi.specs' => ['v1' => ['match' => ['prefix' => 'api/v1/*']]]]);
    app()->forgetScopedInstances();

    $this
        ->artisan('openapi:why api/v1/flights')
        ->expectsOutputToContain('Route:')
        ->expectsOutputToContain('default:')
        ->expectsOutputToContain('v1:')
        ->expectsOutputToContain('Result:')
        ->assertSuccessful();
});

it('explains a globally-filtered route as filtered, not as not-found', function (): void {
    config(['openapi.filters' => [FlightsRouteFilter::class]]);
    app()->forgetScopedInstances();

    // The route is discovered (discover() stays unfiltered) and explained as excluded by the
    // global filter, never reported as not-found. Chained expectsOutputToContain matches the
    // buffer in document order, so the summary line (which already names the filter class) is
    // asserted last; a separate class-name assertion would consume the trace-line occurrence first.
    $this
        ->artisan('openapi:why api/v1/flights')
        ->expectsOutputToContain('Route:')
        ->expectsOutputToContain('global-filter')
        ->expectsOutputToContain('excluded by global filter ' . FlightsRouteFilter::class)
        ->assertSuccessful();
});

it('exits non-zero when the substring matches multiple routes', function (): void {
    Route::get('api/v2/flights', fn() => 'y')->name('v2.flights.index');
    app()->forgetScopedInstances();

    $this
        ->artisan('openapi:why api/')
        ->assertFailed();
});

it('exits non-zero when no route matches', function (): void {
    $this
        ->artisan('openapi:why nonsense/xyz')
        ->assertFailed();
});

it('--env overrides app environment for Hide/Expose evaluation', function (): void {
    $this
        ->artisan('openapi:why api/v1/flights --for-env=production')
        ->expectsOutputToContain('environment: production')
        ->assertSuccessful();
});
