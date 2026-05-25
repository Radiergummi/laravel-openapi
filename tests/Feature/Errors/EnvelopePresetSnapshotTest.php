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

beforeEach(function (): void {
    Route::middleware('auth')
        ->get('/widgets/{id}', [ErrorEnvelopeFixtureController::class, 'show'])
        ->name('widgets.show');
});

dataset('envelopes', [
    'none'     => ['none', null, null],
    'laravel'  => ['laravel', 'application/json', 'Error'],
    'rfc7807'  => ['rfc7807', 'application/problem+json', 'Problem'],
    'json-api' => ['json-api', 'application/vnd.api+json', 'ErrorDocument'],
]);

it('renders the configured envelope on error responses', function (
    string $preset,
    ?string $expectedMediaType,
    ?string $expectedSchemaKey,
): void {
    config()->set('openapi.error_envelope', $preset);

    $spec = generateSpec();

    $unauthorizedResponse = $spec['components']['responses']['Unauthorized'];

    if ($preset === 'none') {
        // Bodyless — description present, no content key.
        expect($unauthorizedResponse)->toHaveKey('description');
        expect($unauthorizedResponse)->not->toHaveKey('content');

        return;
    }

    assert($expectedMediaType !== null && $expectedSchemaKey !== null);

    expect($unauthorizedResponse)->toHaveKey('content');
    expect($unauthorizedResponse['content'])->toHaveKey($expectedMediaType);

    $schema = $unauthorizedResponse['content'][$expectedMediaType]['schema'];

    expect($schema)->toHaveKey('$ref');
    expect($schema['$ref'])->toBe('#/components/schemas/' . $expectedSchemaKey);
})->with('envelopes');
