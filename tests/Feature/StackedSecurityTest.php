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

it('emits one security requirement entry per stacked #[Security] attribute', function (): void {
    config()->set('openapi.security_schemes', [
        'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
        'apiKey' => ['type' => 'apiKey', 'name' => 'X-API-Key', 'in' => 'header'],
    ]);

    Route::get('/edge/stacked-security', [EdgeCaseFixtureController::class, 'stackedSecurityAction']);

    $spec = generateSpec();

    expect($spec['paths']['/edge/stacked-security']['get']['security'])->toBe([
        ['bearer' => ['admin']],
        ['apiKey' => ['admin']],
    ]);
});
