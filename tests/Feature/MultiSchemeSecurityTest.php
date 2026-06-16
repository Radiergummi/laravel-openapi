<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\AuthoringFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\EdgeCaseFixtureController;

uses()->group('openapi');

it('mixes default-scheme and per-operation-scheme requirements in the same document', function (): void {
    config()->set('openapi.security_schemes.bearer', [
        'type' => 'http',
        'scheme' => 'bearer',
        'bearerFormat' => 'JWT',
    ]);

    // Default route — no explicit scheme, picks up the Passport oauth2 pair.
    Route::get('/edge/default-scheme', [AuthoringFixtureController::class, 'scopedAction']);

    // Per-operation override — `#[Security([], scheme: 'bearer')]` on the action.
    Route::get('/edge/bearer-only', [EdgeCaseFixtureController::class, 'bearerOnlyAction']);

    $spec = generateSpec();

    expect($spec['paths']['/edge/default-scheme']['get']['security'])->toBe([
        ['oauth2' => ['admin', 'projects']],
        ['oauth2ClientCredentials' => ['admin', 'projects']],
    ]);

    expect($spec['paths']['/edge/bearer-only']['get']['security'])->toBe([
        ['bearer' => []],
    ]);

    expect($spec['components']['securitySchemes'])
        ->toHaveKey('bearer')
        ->and($spec['components']['securitySchemes']['bearer']['type'])->toBe('http');
});
