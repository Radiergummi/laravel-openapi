<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Console;

use Illuminate\Support\Facades\Route;

uses()->group('openapi');

beforeEach(function (): void {
    Route::get('api/v1/flights', fn() => 'x')->name('v1.flights.index');
    app()->forgetScopedInstances();
});

it('explains why a route is included in each spec', function (): void {
    config(['openapi.specs' => ['v1' => ['match' => ['prefix' => 'api/v1/*']]]]);
    app()->forgetScopedInstances();

    $this->artisan('openapi:why api/v1/flights')
        ->expectsOutputToContain('Route:')
        ->expectsOutputToContain('default:')
        ->expectsOutputToContain('v1:')
        ->expectsOutputToContain('Result:')
        ->assertSuccessful();
});

it('exits non-zero when the substring matches multiple routes', function (): void {
    Route::get('api/v2/flights', fn() => 'y')->name('v2.flights.index');
    app()->forgetScopedInstances();

    $this->artisan('openapi:why api/')
        ->assertFailed();
});

it('exits non-zero when no route matches', function (): void {
    $this->artisan('openapi:why nonsense/xyz')
        ->assertFailed();
});

it('--env overrides app environment for Hide/Expose evaluation', function (): void {
    $this->artisan('openapi:why api/v1/flights --for-env=production')
        ->expectsOutputToContain('environment: production')
        ->assertSuccessful();
});
