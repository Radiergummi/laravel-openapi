<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionMethodController;

uses()->group('openapi', 'lint');

beforeEach(function (): void {
    // Mirrors an over-registered resourceful route: the controller has `index` but not `destroy`.
    Route::get('lint-fixtures/action-method/present', [ActionMethodController::class, 'index'])
        ->name('lint.action-method.present');
    Route::delete('lint-fixtures/action-method/missing', [ActionMethodController::class, 'destroy'])
        ->name('lint.action-method.missing');
});

it('flags a route whose controller method does not exist', function (): void {
    $this->artisan('openapi:lint', [
        '--level' => 1,
        '--only' => 'operation.action-method-missing',
        '--uri' => 'lint-fixtures/action-method/missing',
        '--format' => 'json',
    ])->assertExitCode(1);
});

it('does not flag a route whose controller method exists', function (): void {
    $this->artisan('openapi:lint', [
        '--level' => 1,
        '--only' => 'operation.action-method-missing',
        '--uri' => 'lint-fixtures/action-method/present',
        '--format' => 'json',
    ])->assertExitCode(0);
});
