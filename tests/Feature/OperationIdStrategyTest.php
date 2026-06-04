<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;

uses()->group('openapi');

beforeEach(function (): void {
    Route::get('/users', static fn(): array => [])->name('users.index');
    Route::get('/health/check', static fn(): array => []); // unnamed
});

it('defaults to the route-name strategy (byte-stable)', function (): void {
    $spec = generateSpec();

    expect($spec['paths']['/users']['get']['operationId'])->toBe('users.index')
        ->and($spec['paths']['/health/check']['get']['operationId'])->toBe('get_health_check');
});

it('honours the method-path strategy, ignoring route names', function (): void {
    config()->set('openapi.operation_id_strategy', 'method-path');

    $spec = generateSpec();

    expect($spec['paths']['/users']['get']['operationId'])->toBe('get_users')
        ->and($spec['paths']['/health/check']['get']['operationId'])->toBe('get_health_check');
});

it('falls back to the default strategy for an unknown value', function (): void {
    config()->set('openapi.operation_id_strategy', 'nonsense');

    $spec = generateSpec();

    expect($spec['paths']['/users']['get']['operationId'])->toBe('users.index');
});

it('produces codegen-safe ids under every strategy', function (string $strategy): void {
    config()->set('openapi.operation_id_strategy', $strategy);

    $spec = generateSpec();

    foreach ($spec['paths'] as $methods) {
        foreach ($methods as $operation) {
            expect($operation['operationId'])->toMatch('/^[A-Za-z][A-Za-z0-9._-]*$/');
        }
    }
})->with(['route-name', 'method-path']);
