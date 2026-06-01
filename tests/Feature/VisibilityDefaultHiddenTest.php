<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\VisibilityFixtureController;

uses()->group('openapi');

beforeEach(function (): void {
    config()->set('openapi.visibility.default', 'hidden');
    // Prevent Spatie Data from querying the (non-existent) cache DB table when
    // forgetScopedInstances() causes DataConfig to be re-resolved mid-test.
    config()->set('data.structure_caching.enabled', false);

    Route::get('/visibility/bare', [VisibilityFixtureController::class, 'bare']);
    Route::get('/visibility/explicit', [VisibilityFixtureController::class, 'explicitlyExposed']);
    Route::get('/visibility/staging-only', [VisibilityFixtureController::class, 'exposedInStagingOnly']);

    // Pick up the changed config in the scoped VisibilityResolver binding.
    app()->forgetScopedInstances();
});

it('hides routes without #[Expose] in hidden-default mode', function (): void {
    $spec = generateSpec();

    expect($spec['paths'])->not->toHaveKey('/visibility/bare');
});

it('exposes routes carrying unconditional #[Expose] in hidden-default mode', function (): void {
    $spec = generateSpec();

    expect($spec['paths'])->toHaveKey('/visibility/explicit');
});

it('hides env-scoped #[Expose] outside the matching environment', function (): void {
    // Default test env is "testing" — Expose(only: ['staging']) should not apply.
    $spec = generateSpec();

    expect($spec['paths'])->not->toHaveKey('/visibility/staging-only');
});

it('exposes env-scoped #[Expose] inside the matching environment', function (): void {
    app()['env'] = 'staging';

    $spec = generateSpec();

    expect($spec['paths'])->toHaveKey('/visibility/staging-only');
});
