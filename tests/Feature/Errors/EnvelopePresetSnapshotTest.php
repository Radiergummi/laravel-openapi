<?php

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

    if ($preset === 'none') {
        // Bodyless — shared component exists with description but no content.
        $unauthorizedResponse = $spec['components']['responses']['Unauthorized'];
        expect($unauthorizedResponse)->toHaveKey('description');
        expect($unauthorizedResponse)->not->toHaveKey('content');

        return;
    }

    assert($expectedMediaType !== null && $expectedSchemaKey !== null);
    assert($preset === 'laravel' || $preset === 'rfc7807' || $preset === 'json-api');

    // When a resolver produces a body, responses are inlined per-operation to avoid
    // first-write-wins collisions on shared components (e.g. two operations at 422
    // with different resolver outputs). Look up the inline path on the operation.
    $operation = $spec['paths']['/widgets/{id}']['get'];

    // 401 Unauthorized — inlined on the operation
    $unauthorizedResponse = $operation['responses']['401'];
    expect($unauthorizedResponse)->toHaveKey('content');
    expect($unauthorizedResponse['content'])->toHaveKey($expectedMediaType);

    $schema = $unauthorizedResponse['content'][$expectedMediaType]['schema'];
    expect($schema)->toHaveKey('$ref');
    expect($schema['$ref'])->toBe('#/components/schemas/' . $expectedSchemaKey);

    // 422 ValidationFailed — inlined on the operation
    $validationFailedResponse = $operation['responses']['422'];
    expect($validationFailedResponse)->toHaveKey('content');
    expect($validationFailedResponse['content'])->toHaveKey($expectedMediaType);

    $validationSchema = $validationFailedResponse['content'][$expectedMediaType]['schema'];
    expect($validationSchema)->toHaveKey('$ref');

    // laravel and rfc7807 use specialized validation schemas; json-api uses uniform ref
    $expectedValidationSchemaKey = match ($preset) {
        'laravel'  => 'ValidationError',
        'rfc7807'  => 'ValidationProblem',
        'json-api' => 'ErrorDocument',
    };
    expect($validationSchema['$ref'])->toBe('#/components/schemas/' . $expectedValidationSchemaKey);

    // 404 NotFound — inlined on the operation
    $notFoundResponse = $operation['responses']['404'];
    expect($notFoundResponse)->toHaveKey('content');
    expect($notFoundResponse['content'])->toHaveKey($expectedMediaType);

    $notFoundSchema = $notFoundResponse['content'][$expectedMediaType]['schema'];
    expect($notFoundSchema)->toHaveKey('$ref');

    // Generic non-validation errors use the preset's base schema
    $expectedNotFoundSchemaKey = match ($preset) {
        'laravel'  => 'Error',
        'rfc7807'  => 'Problem',
        'json-api' => 'ErrorDocument',
    };
    expect($notFoundSchema['$ref'])->toBe('#/components/schemas/' . $expectedNotFoundSchemaKey);
})->with('envelopes');
