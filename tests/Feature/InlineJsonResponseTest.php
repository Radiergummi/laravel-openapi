<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Tests\Fixtures\InlineJsonFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\InlineJsonWithAttributeController;

use function array_any;
use function str_contains;

uses()->group('openapi');

// region Inferred responses

it('emits an object response schema from a literal response()->json() body', function (): void {
    Route::get('/oa-fixture/inline-json', [InlineJsonFixtureController::class, 'literalObject']);

    $spec = generateSpec();
    $response = $spec['paths']['/oa-fixture/inline-json']['get']['responses']['200'];

    expect($response['description'])->toBe('OK')
        ->and($response['content']['application/json']['schema']['type'])->toBe('object')
        ->and($response['content']['application/json']['schema']['properties'])
        ->toHaveKeys(['message', 'success', 'attempts', 'score'])
        ->and($response['content']['application/json']['schema']['properties']['message']['type'])->toBe('string');
});

it('documents the body under the literal status argument', function (): void {
    Route::post('/oa-fixture/inline-json', [InlineJsonFixtureController::class, 'literalStatus']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/inline-json']['post']['responses'];

    expect($responses)->toHaveKey('201')
        ->and($responses['201']['description'])->toBe('Created')
        ->and($responses['201']['content']['application/json']['schema']['properties'])->toHaveKey('id');
});

it('keeps a partial literal property as an unconstrained schema end to end', function (): void {
    Route::get('/oa-fixture/inline-json', [InlineJsonFixtureController::class, 'partialLiteral']);

    $spec = generateSpec();
    $properties = $spec['paths']['/oa-fixture/inline-json']['get']['responses']['200']
        ['content']['application/json']['schema']['properties'];

    expect($properties)->toHaveKeys(['logs', 'success'])
        ->and($properties['logs'])->toBe([])
        ->and($properties['success']['type'])->toBe('boolean');
});

it('resolves named data and status arguments', function (): void {
    Route::post('/oa-fixture/inline-json', [InlineJsonFixtureController::class, 'namedArguments']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/inline-json']['post']['responses'];

    expect($responses)->toHaveKey('201')
        ->and($responses['201']['content']['application/json']['schema']['properties'])->toHaveKey('queued');
});

// endregion

// region Precedence

it('prefers an explicit #[Response] attribute over the inferred body', function (): void {
    Route::get('/oa-fixture/attributed', [InlineJsonWithAttributeController::class, 'show']);

    $spec = generateSpec();
    $response = $spec['paths']['/oa-fixture/attributed']['get']['responses']['200'];

    expect($response['description'])->toBe('Authored response that must win')
        ->and($response['content']['application/json']['schema']['properties'])->toHaveKey('authored')
        ->and($response['content']['application/json']['schema']['properties'])->not->toHaveKey('inferred');
});

it('prefers the typed return value over the body scan', function (): void {
    Route::get('/oa-fixture/typed', [InlineJsonFixtureController::class, 'typedReturnWithJsonBody']);

    $spec = generateSpec();
    $schema = $spec['paths']['/oa-fixture/typed']['get']['responses']['200']['content']['application/json']['schema'];

    expect($schema['$ref'])->toBe('#/components/schemas/Article')
        ->and($schema)->not->toHaveKey('properties');
});

// endregion

// region Degradation

it('falls back to the bare 200 and logs a note for a variable body', function (): void {
    Route::get('/oa-fixture/variable', [InlineJsonFixtureController::class, 'variableBody']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $spec = generateSpec();
    $response = $spec['paths']['/oa-fixture/variable']['get']['responses']['200'];

    expect($response)->not->toHaveKey('content');

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'no statically readable body'),
    );

    expect($noted)->toBeTrue();
});

// endregion
