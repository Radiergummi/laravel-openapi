<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\HideExposeConflictController;

uses()->group('openapi', 'lint');

beforeEach(function (): void {
    // Prevent Spatie Data from querying the (non-existent) cache DB table when
    // forgetScopedInstances() causes DataConfig to be re-resolved mid-test.
    config()->set('data.structure_caching.enabled', false);

    Route::get('lint-fixtures/visibility/both', [HideExposeConflictController::class, 'both'])
        ->name('lint.visibility.both');
    Route::get('lint-fixtures/visibility/overlap', [HideExposeConflictController::class, 'envOverlap'])
        ->name('lint.visibility.overlap');
    Route::get('lint-fixtures/visibility/disjoint', [HideExposeConflictController::class, 'envDisjoint'])
        ->name('lint.visibility.disjoint');
});

it('reports an unconditional Hide+Expose conflict', function (): void {
    $this->artisan('openapi:lint', [
        '--level' => 1,
        '--only' => 'visibility.hide-expose-conflict',
        '--uri' => 'lint-fixtures/visibility/both',
        '--format' => 'json',
    ])->assertExitCode(1);
});

it('reports a Hide+Expose conflict that overlaps in the current env', function (): void {
    app()['env'] = 'production';

    $this->artisan('openapi:lint', [
        '--level' => 1,
        '--only' => 'visibility.hide-expose-conflict',
        '--uri' => 'lint-fixtures/visibility/overlap',
        '--format' => 'json',
    ])->assertExitCode(1);
});

it('does not report when Hide and Expose env scopes are disjoint in the current env', function (): void {
    app()['env'] = 'production';

    $this->artisan('openapi:lint', [
        '--level' => 1,
        '--only' => 'visibility.hide-expose-conflict',
        '--uri' => 'lint-fixtures/visibility/disjoint',
        '--format' => 'json',
    ])->assertExitCode(0);
});
