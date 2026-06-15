<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\RequestFieldMethodFixtureController;

uses()->group('openapi');

beforeEach(function (): void {
    Route::post('/oa-fixture/request-field/store', [RequestFieldMethodFixtureController::class, 'store']);
    $this->spec = generateSpec();
});

it('builds a request body schema from method-level #[RequestField] attributes', function (): void {
    $op = $this->spec['paths']['/oa-fixture/request-field/store']['post'] ?? [];

    $ref = $op['requestBody']['content']['application/json']['schema']['$ref'] ?? null;
    expect($ref)->toStartWith('#/components/schemas/');

    $key = substr((string) $ref, strlen('#/components/schemas/'));
    $schema = $this->spec['components']['schemas'][$key] ?? [];

    expect($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKeys(['domain', 'php_version', 'aliases'])
        ->and($schema['properties']['domain']['type'])->toBe('string')
        ->and($schema['properties']['domain']['format'])->toBe('hostname')
        ->and($schema['properties']['php_version']['default'])->toBe('8.4')
        ->and($schema['properties']['aliases']['type'])->toBe('array')
        ->and($schema['properties']['aliases']['items']['type'])->toBe('string')
        ->and($schema['required'])->toBe(['domain']);
});

it('resolves a class-string #[RequestField] type/items to a $ref end-to-end', function (): void {
    Route::post('/oa-fixture/request-field/store-with-ref', [RequestFieldMethodFixtureController::class, 'storeWithRef']);
    $spec = generateSpec();

    $op = $spec['paths']['/oa-fixture/request-field/store-with-ref']['post'] ?? [];
    $ref = $op['requestBody']['content']['application/json']['schema']['$ref'] ?? null;
    $key = substr((string) $ref, strlen('#/components/schemas/'));
    $schema = $spec['components']['schemas'][$key] ?? [];

    expect($schema['properties']['owner']['$ref'])->toBe('#/components/schemas/CircleData')
        ->and($schema['properties']['shapes']['type'])->toBe('array')
        ->and($schema['properties']['shapes']['items']['$ref'])->toBe('#/components/schemas/CircleData')
        ->and($spec['components']['schemas'])->toHaveKey('CircleData');
})->group('plugin:spatie-data');
