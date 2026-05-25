<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\Errors\ErrorEnvelopeFixtureController;

uses()->group('openapi');

it('inlines distinct 422 bodies per operation when two routes throw different 422 exceptions', function (): void {
    // Route A: @throws ValidationException → ValidationError schema
    Route::post('/widgets', [ErrorEnvelopeFixtureController::class, 'show'])
        ->name('widgets.store');

    // Route B: @throws UnprocessableEntityHttpException → generic Error schema
    Route::put('/widgets/{id}', [ErrorEnvelopeFixtureController::class, 'update'])
        ->name('widgets.update');

    config()->set('openapi.error_envelope', 'laravel');

    $spec = generateSpec();

    // Route A (POST /widgets) — 422 should reference ValidationError
    $routeA422 = $spec['paths']['/widgets']['post']['responses']['422'];
    expect($routeA422)->toHaveKey('content');
    $routeARef = $routeA422['content']['application/json']['schema']['$ref'];
    expect($routeARef)->toBe('#/components/schemas/ValidationError');

    // Route B (PUT /widgets/{id}) — 422 should reference the generic Error
    $routeB422 = $spec['paths']['/widgets/{id}']['put']['responses']['422'];
    expect($routeB422)->toHaveKey('content');
    $routeBRef = $routeB422['content']['application/json']['schema']['$ref'];
    expect($routeBRef)->toBe('#/components/schemas/Error');

    // The two must be different — proving collision is gone.
    expect($routeARef)->not->toBe($routeBRef);
});
