<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Tests\Fixtures\ConstructorMiddleware\ConstructorMiddlewareChildController;
use Radiergummi\OpenApi\Tests\Fixtures\ConstructorMiddleware\ConstructorMiddlewareFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\ConstructorMiddleware\StaticMiddlewareFixtureController;

use function array_any;
use function str_contains;

uses()->group('openapi');

// region Constructor middleware of a non-instantiable controller

it('derives security and the implicit 401 from constructor middleware when instantiation fails', function (): void {
    Route::get('/oa-fixture/reports', [ConstructorMiddlewareFixtureController::class, 'index']);

    $spec = generateSpec();
    $operation = $spec['paths']['/oa-fixture/reports']['get'];

    // Passport is active in the Testbench environment, so the derived default-scheme set
    // includes the oauth2 pair besides sanctum; the sanctum requirement is what this feature adds.
    expect($operation['security'])->toContain(['sanctum' => []])
        ->and($operation['responses'])->toHaveKey('401')
        ->and($spec['components']['securitySchemes']['sanctum']['type'])->toBe('http');
});

it('scopes only() and except() registrations to the matching operations', function (): void {
    Route::get('/oa-fixture/reports', [ConstructorMiddlewareFixtureController::class, 'index']);
    Route::post('/oa-fixture/reports', [ConstructorMiddlewareFixtureController::class, 'store']);

    $spec = generateSpec();
    $index = $spec['paths']['/oa-fixture/reports']['get'];
    $store = $spec['paths']['/oa-fixture/reports']['post'];

    // `throttle:exports` is except('index'): the 429 only appears on store.
    expect($index['responses'])->not->toHaveKey('429')
        ->and($store['responses'])->toHaveKey('429');
});

it('keeps generation alive and notices the degradation instead of crashing', function (): void {
    Route::get('/oa-fixture/reports', [ConstructorMiddlewareFixtureController::class, 'index']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    generateSpec();

    $messages = array_map(static fn(array $record): string => $record['message'], $logger->records);

    expect(array_any($messages, static fn(string $m): bool => str_contains($m, 'could not be instantiated')))
        ->toBeTrue()
        ->and(array_any($messages, static fn(string $m): bool => str_contains($m, 'no statically readable name or scope')))
        ->toBeTrue()
        ->and(array_any($messages, static fn(string $m): bool => str_contains($m, 'conditionally applied')))
        ->toBeTrue();
});

it('lets #[PublicEndpoint] win over constructor-derived security and suppresses the implicit 401', function (): void {
    Route::get('/oa-fixture/reports/health', [ConstructorMiddlewareFixtureController::class, 'health']);

    $spec = generateSpec();
    $operation = $spec['paths']['/oa-fixture/reports/health']['get'];

    // The attribute clears `security` (affirmatively public), so the auth-derived 401 is suppressed
    // too — the document must not claim an endpoint is public *and* document a 401 (#259). The
    // throttle-derived 429 is independent of authentication and stays (`health` also carries
    // `throttle:exports`).
    expect($operation['security'] ?? [])->toBe([])
        ->and($operation['responses'])->not->toHaveKey('401')
        ->and($operation['responses'])->toHaveKey('429');
});

// endregion

// region Inherited base-controller constructor

it('reads middleware applied by a parent constructor', function (): void {
    Route::get('/oa-fixture/inherited', [ConstructorMiddlewareChildController::class, 'index']);

    $spec = generateSpec();
    $operation = $spec['paths']['/oa-fixture/inherited']['get'];

    // `auth:api` resolves to the Passport default pair in the Testbench environment.
    expect($operation['security'])->toBe([['oauth2' => []], ['oauth2ClientCredentials' => []]])
        ->and($operation['responses'])->toHaveKey('401');
});

// endregion

// region HasMiddleware static form (pinned: resolved natively by the framework)

it('documents HasMiddleware static middleware without any constructor scan', function (): void {
    Route::get('/oa-fixture/static', [StaticMiddlewareFixtureController::class, 'index']);
    Route::get('/oa-fixture/static/{id}', [StaticMiddlewareFixtureController::class, 'show']);

    $spec = generateSpec();
    $index = $spec['paths']['/oa-fixture/static']['get'];
    $show = $spec['paths']['/oa-fixture/static/{id}']['get'];

    expect($index['security'])->toContain(['sanctum' => []])
        ->and($index['responses'])->toHaveKey('401')
        ->and($show['security'] ?? [])->toBe([])
        ->and($show['responses'])->not->toHaveKey('401');
});

// endregion
