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
        ->and($schema['properties'])->toHaveKeys(['domain', 'php_version'])
        ->and($schema['properties']['domain']['type'])->toBe('string')
        ->and($schema['properties']['domain']['format'])->toBe('hostname')
        ->and($schema['properties']['php_version']['default'])->toBe('8.4')
        ->and($schema['required'])->toBe(['domain']);
});
