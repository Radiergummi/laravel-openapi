<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\EdgeCaseFixtureController;

uses()->group('openapi');

it('falls back to a bare 200 response when the action return type is a union of Data classes', function (): void {
    Route::get('/edge/union-return', [EdgeCaseFixtureController::class, 'unionReturnAction']);

    $spec = generateSpec();

    // Current behavior, pinned to surface regressions: union return types reach neither
    // DataResponseResolver (rejects non-ReflectionNamedType) nor ReturnTypeExtractor's
    // generic-argument path, so the operation degrades to a description-only 200. If the
    // generator grows oneOf support for unions, update this assertion (the bug to watch
    // out for is the *silent* degradation, not the shape itself).
    $response = $spec['paths']['/edge/union-return']['get']['responses']['200'];

    expect($response)->not->toHaveKey('content')
        ->and($response['description'])->toBe('OK');
});
