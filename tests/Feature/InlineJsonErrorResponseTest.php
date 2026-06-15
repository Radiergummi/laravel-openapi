<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\InlineJsonErrorFixtureController;

uses()->group('openapi');

// region Literal body wins over the envelope

it('emits a 403 whose body is the literal json() schema, inlined per operation', function (): void {
    Route::get('/oa-fixture/json-error', [InlineJsonErrorFixtureController::class, 'straightLineError']);

    $spec = generateSpec();
    $response = $spec['paths']['/oa-fixture/json-error']['get']['responses']['403'];

    // The literal body wins over the configured envelope and is inlined (no $ref to a shared component).
    expect($response)->not->toHaveKey('$ref')
        ->and($response['content']['application/json']['schema']['type'])->toBe('object')
        ->and($response['content']['application/json']['schema']['properties'])->toHaveKey('message')
        ->and($response['content']['application/json']['schema']['properties']['message']['type'])->toBe('string');
});

it('keeps the conditional success response alongside the inferred 403', function (): void {
    Route::get('/oa-fixture/json-guarded', [
        InlineJsonErrorFixtureController::class,
        'guardedSuccessTerminalError',
    ]);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/json-guarded']['get']['responses'];

    // The 403 error is inferred; the operation still carries a success response (the conditional
    // success path is not evicted by the terminal error literal).
    expect($responses)->toHaveKey('403')
        ->and($responses['403']['content']['application/json']['schema']['properties'])->toHaveKey('message');

    $successKeys = array_filter(array_keys($responses), static fn(string $code): bool => $code[0] === '2');

    expect($successKeys)->not->toBeEmpty();
});

// endregion

// region Envelope fallback when only the status is readable

it('falls back to the shared status component when the body is non-literal', function (): void {
    Route::get('/oa-fixture/json-error-dynamic', [InlineJsonErrorFixtureController::class, 'nonLiteralBody']);

    $spec = generateSpec();
    $response = $spec['paths']['/oa-fixture/json-error-dynamic']['get']['responses']['403'];

    // No literal body to inline → the status-only descriptor shares the named 403 component.
    expect($response)->toHaveKey('$ref')
        ->and($response['$ref'])->toBe('#/components/responses/Forbidden');
});

// endregion

// region #[Response] override wins

it('lets an explicit #[Response(403)] win over the inferred literal body', function (): void {
    Route::get('/oa-fixture/json-error-authored', [InlineJsonErrorFixtureController::class, 'authoredOverride']);

    $spec = generateSpec();
    $response = $spec['paths']['/oa-fixture/json-error-authored']['get']['responses']['403'];

    expect($response['description'])->toBe('Authored forbidden')
        // The attribute response stands; the inferred literal body is dropped for that status.
        ->and($response)->not->toHaveKey('content');
});

// endregion
