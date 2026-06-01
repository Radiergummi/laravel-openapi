<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\Errors\ErrorEnvelopeFixtureController;

uses()->group('openapi');

it('default config (error_envelope=none) emits no body on error responses', function (): void {
    // Do not override config — exercise the shipped default ('none').
    Route::middleware('auth')
        ->get('/widgets/{id}', [ErrorEnvelopeFixtureController::class, 'show'])
        ->name('widgets.show');

    $spec = generateSpec();

    // The well-known Unauthorized response component should exist and have description but no content.
    expect($spec['components']['responses']['Unauthorized'])->toHaveKey('description');
    expect($spec['components']['responses']['Unauthorized'])->not->toHaveKey('content');
});
