<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\RawSchema\RawSchemaController;

uses()->group('openapi');

/**
 * Resolves the component schema body referenced by a request body or response.
 *
 * @param array<string, mixed> $spec
 *
 * @return array<string, mixed>
 */
function rawSchemaComponentFor(array $spec, string $ref): array
{
    $name = basename(str_replace('#/components/schemas/', '', $ref));

    return $spec['components']['schemas'][$name] ?? [];
}

it('replaces a Spatie Data body with the literal #[RawSchema] and skips inference', function (): void {
    Route::post('/oa-fixture/raw-data', [RawSchemaController::class, 'data']);

    $spec = generateSpec();

    $ref = $spec['paths']['/oa-fixture/raw-data']['post']['requestBody']['content']['application/json']['schema']['$ref'];
    $component = rawSchemaComponentFor($spec, $ref);

    expect($component['type'])->toBe('object')
        ->and($component['required'])->toBe(['kind'])
        ->and($component['properties'])->toHaveKey('kind')
        // `secret` would be inferred from the constructor without #[RawSchema].
        ->and($component['properties'])->not->toHaveKey('secret');
});

it('replaces an API Resource body with the literal #[RawSchema] (oneOf composition)', function (): void {
    Route::get('/oa-fixture/raw-resource', [RawSchemaController::class, 'resource']);

    $spec = generateSpec();

    $response = $spec['paths']['/oa-fixture/raw-resource']['get']['responses']['200'] ?? null;
    expect($response)->not->toBeNull();

    // Laravel wraps a single resource under `data`; the wrapped schema is the raw component.
    $ref = $response['content']['application/json']['schema']['properties']['data']['$ref'];
    $component = rawSchemaComponentFor($spec, $ref);

    expect($component)->toHaveKey('oneOf')
        ->and($component['oneOf'])->toHaveCount(2)
        ->and($component)->not->toHaveKey('ignored');
});

it('replaces a FormRequest body with the literal #[RawSchema] and skips the rules() read', function (): void {
    Route::post('/oa-fixture/raw-form-request', [RawSchemaController::class, 'formRequest']);

    $spec = generateSpec();

    $ref = $spec['paths']['/oa-fixture/raw-form-request']['post']['requestBody']['content']['application/json']['schema']['$ref'];
    $component = rawSchemaComponentFor($spec, $ref);

    expect($component['required'])->toBe(['token'])
        ->and($component['properties'])->toHaveKey('token')
        // `email` would be mapped from rules() without #[RawSchema].
        ->and($component['properties'])->not->toHaveKey('email');
});

it('lets class-level #[RawSchema] win over property-level field attributes', function (): void {
    Route::post('/oa-fixture/raw-precedence', [RawSchemaController::class, 'withFieldAttribute']);

    $spec = generateSpec();

    $ref = $spec['paths']['/oa-fixture/raw-precedence']['post']['requestBody']['content']['application/json']['schema']['$ref'];
    $component = rawSchemaComponentFor($spec, $ref);

    expect($component['properties'])->toHaveKey('only')
        ->and($component['properties'])->not->toHaveKey('annotated');
});

it('produces a valid document even when #[RawSchema] carries an unsupported keyword', function (): void {
    Route::post('/oa-fixture/raw-unsupported', [RawSchemaController::class, 'unsupported']);

    $spec = generateSpec();

    $ref = $spec['paths']['/oa-fixture/raw-unsupported']['post']['requestBody']['content']['application/json']['schema']['$ref'];
    $component = rawSchemaComponentFor($spec, $ref);

    // The `if` keyword is dropped at build time; the surviving body is intact.
    expect($component)->not->toHaveKey('if')
        ->and($component['properties'])->toHaveKey('kind');
});
