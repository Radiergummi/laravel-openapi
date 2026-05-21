<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\VisibilityNoOpController;

uses()->group('openapi', 'lint');

beforeEach(function (): void {
    Route::get('lint-fixtures/visibility-noop/expose-public', [VisibilityNoOpController::class, 'exposeInPublic'])
        ->name('lint.visibility-noop.expose-public');
    Route::get('lint-fixtures/visibility-noop/expose-staging', [VisibilityNoOpController::class, 'envScopedExposeInPublic'])
        ->name('lint.visibility-noop.expose-staging');
    Route::get('lint-fixtures/visibility-noop/hide-hidden', [VisibilityNoOpController::class, 'hideInHidden'])
        ->name('lint.visibility-noop.hide-hidden');
});

it('flags unconditional #[Expose] in public-default mode', function (): void {
    config()->set('openapi.visibility.default', 'public');

    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--only' => 'visibility.attribute-no-op',
        '--path' => 'lint-fixtures/visibility-noop/expose-public',
        '--format' => 'json',
    ])->assertExitCode(1);
});

it('does not flag env-scoped #[Expose] in public-default mode', function (): void {
    config()->set('openapi.visibility.default', 'public');

    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--only' => 'visibility.attribute-no-op',
        '--path' => 'lint-fixtures/visibility-noop/expose-staging',
        '--format' => 'json',
    ])->assertExitCode(0);
});

it('flags unconditional #[Hide] in hidden-default mode', function (): void {
    config()->set('openapi.visibility.default', 'hidden');
    // The shared Spatie Data scoped binding needs a refresh trick — same as in tests/Feature/VisibilityDefaultHiddenTest.php
    config()->set('data.structure_caching.enabled', false);

    $this->artisan('openapi:lint', [
        '--level' => 2,
        '--only' => 'visibility.attribute-no-op',
        '--path' => 'lint-fixtures/visibility-noop/hide-hidden',
        '--format' => 'json',
    ])->assertExitCode(1);
});
