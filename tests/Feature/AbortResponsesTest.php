<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Tests\Fixtures\AbortFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\AbortWithAttributeController;

use function array_any;
use function str_contains;

uses()->group('openapi');

// region Inferred error responses

it('emits the 4xx responses for each whitelisted abort call shape', function (): void {
    Route::get('/oa-fixture/aborts', [AbortFixtureController::class, 'multipleStatuses']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/aborts']['get']['responses'];

    expect($responses)
        ->toHaveKeys(['401', '403', '404'])
        ->and($responses['403']['description'])->toBe('Admins only')
        ->and($responses['404']['description'])->toBe('Order not found')
        // No message on the abort_if(…, 401) — the default description shares the named component.
        ->and($responses['401']['$ref'])->toBe('#/components/responses/Unauthorized');
});

it('keeps route-authored messages inlined instead of poisoning the shared status component', function (): void {
    Route::get('/oa-fixture/aborts', [AbortFixtureController::class, 'multipleStatuses']);
    Route::get('/oa-fixture/plain-403', [AbortFixtureController::class, 'plainAbort']);

    $spec = generateSpec();

    expect($spec['paths']['/oa-fixture/aborts']['get']['responses']['403']['description'])
        ->toBe('Admins only')
        ->and($spec['paths']['/oa-fixture/plain-403']['get']['responses']['403']['$ref'])
        ->toBe('#/components/responses/Forbidden')
        ->and($spec['components']['responses']['Forbidden']['description'])->toBe('Forbidden');
});

it('emits the response for an abort with a class-constant status', function (): void {
    Route::put('/oa-fixture/prospects', [AbortFixtureController::class, 'classConstantStatus']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/prospects']['put']['responses'];

    expect($responses)
        ->toHaveKey('403')
        ->and($responses['403']['description'])->toBe('Cannot update a user prospect.');
});

it('emits a 404 with the abort message as description', function (): void {
    Route::delete('/oa-fixture/orders/abort', [AbortFixtureController::class, 'abortWithMessage']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/orders/abort']['delete']['responses'];

    expect($responses)
        ->toHaveKey('404')
        ->and($responses['404']['description'])->toBe('Order not found');
});

// endregion

// region Attribute precedence

it('prefers an explicit #[Response] attribute over the inferred abort response', function (): void {
    Route::get('/oa-fixture/attributed', [AbortWithAttributeController::class, 'show']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/attributed']['get']['responses'];

    expect($responses['403']['description'])->toBe('You shall not pass');
});

// endregion

// region Skip path

it('emits no error response and logs a note for a non-literal status', function (): void {
    Route::get('/oa-fixture/dynamic-abort', [AbortFixtureController::class, 'dynamicStatus']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/dynamic-abort']['get']['responses'];

    expect($responses)->not->toHaveKeys(['400', '401', '403', '404']);

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'no statically readable status code'),
    );

    expect($noted)->toBeTrue();
});

it('emits no error response for a literal redirect abort', function (): void {
    Route::get('/oa-fixture/redirect-abort', [AbortFixtureController::class, 'redirectAbort']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/redirect-abort']['get']['responses'];

    expect($responses)->not->toHaveKey('302');
});

// endregion
