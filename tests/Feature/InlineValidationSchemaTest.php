<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Tests\Fixtures\InlineValidation\Admin\CouponController as AdminCouponController;
use Radiergummi\OpenApi\Tests\Fixtures\InlineValidation\Shop\CouponController as ShopCouponController;
use Radiergummi\OpenApi\Tests\Fixtures\InlineValidationFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\InlineValidationInvokableController;

uses()->group('openapi');

// region Helpers

/**
 * Extracts the component schema for the request body of the given operation.
 *
 * @param array<string, mixed> $spec
 *
 * @return array<string, mixed>
 */
function inlineValidationBodySchema(array $spec, string $path, string $verb, string $mediaType = 'application/json'): array
{
    $reference = $spec['paths'][$path][$verb]['requestBody']['content'][$mediaType]['schema']['$ref'];
    $schemaName = str_replace('#/components/schemas/', '', (string) $reference);

    return $spec['components']['schemas'][$schemaName];
}

// endregion

// region Inline $request->validate([...])

it('emits a request body from an inline $request->validate([...]) call', function (): void {
    Route::post('/oa-fixture/inline', [InlineValidationFixtureController::class, 'store']);

    $spec = generateSpec();
    $schema = inlineValidationBodySchema($spec, '/oa-fixture/inline', 'post');

    expect($schema['properties'])->toHaveKeys(['name', 'email', 'age', 'tags'])
        ->and($schema['required'])->toContain('name', 'email')
        ->and($schema['required'])->not->toContain('age')
        ->and($schema['properties']['name']['type'])->toBe('string')
        ->and($schema['properties']['name']['maxLength'])->toBe(255)
        ->and($schema['properties']['age']['type'])->toBe(['integer', 'null'])
        ->and($schema['properties']['tags']['type'])->toBe('array');
});

it('documents the 422 validation error response for an inline validate() call', function (): void {
    Route::post('/oa-fixture/inline', [InlineValidationFixtureController::class, 'store']);

    $spec = generateSpec();
    $responses = $spec['paths']['/oa-fixture/inline']['post']['responses'];

    expect($responses)->toHaveKey('422');
});

it('uses trailing rule comments as field descriptions', function (): void {
    Route::post('/oa-fixture/inline', [InlineValidationFixtureController::class, 'store']);

    $spec = generateSpec();
    $schema = inlineValidationBodySchema($spec, '/oa-fixture/inline', 'post');

    expect($schema['properties']['name']['description'])->toBe('The display name.')
        ->and($schema['properties']['email']['description'])->toBe('The contact address.');
});

it('resolves class-constant rules: string ruleset, array ruleset, and array element', function (): void {
    Route::post('/oa-fixture/constant-rules', [InlineValidationFixtureController::class, 'constantRules']);

    $spec = generateSpec();
    $schema = inlineValidationBodySchema($spec, '/oa-fixture/constant-rules', 'post');

    expect($schema['properties'])->toHaveKeys(['title', 'status', 'body'])
        ->and($schema['properties']['title']['type'])->toBe('string')
        ->and($schema['properties']['title']['maxLength'])->toBe(120)
        ->and($schema['properties']['status']['enum'])->toBe(['draft', 'published'])
        ->and($schema['properties']['body']['type'])->toBe('string')
        ->and($schema['properties']['body']['maxLength'])->toBe(5000)
        ->and($schema['required'])->toContain('title', 'status', 'body');
});

it('emits multipart/form-data when an inline rule marks a file upload', function (): void {
    Route::post('/oa-fixture/avatar', [InlineValidationFixtureController::class, 'uploadAvatar']);

    $spec = generateSpec();
    $schema = inlineValidationBodySchema($spec, '/oa-fixture/avatar', 'post', 'multipart/form-data');

    expect($schema['properties']['avatar']['format'])->toBe('binary');
});

// endregion

// region Controller-declared rules

it('emits a request body from the controller $rules property', function (): void {
    Route::put('/oa-fixture/from-property', [InlineValidationFixtureController::class, 'fromRulesProperty']);

    $spec = generateSpec();
    $schema = inlineValidationBodySchema($spec, '/oa-fixture/from-property', 'put');

    expect($schema['properties'])->toHaveKeys(['title', 'body'])
        ->and($schema['required'])->toBe(['title'])
        ->and($schema['properties']['title']['maxLength'])->toBe(120);
});

it('emits a request body from a keyed rules() method — the BookStack idiom', function (): void {
    Route::post('/oa-fixture/from-method', [InlineValidationFixtureController::class, 'fromKeyedRulesMethod']);

    $spec = generateSpec();
    $schema = inlineValidationBodySchema($spec, '/oa-fixture/from-method', 'post');

    expect($schema['properties'])->toHaveKeys(['name', 'description'])
        ->and($schema['required'])->toBe(['name']);
});

// endregion

// region Component keys

it('disambiguates component keys for same-short-name controllers', function (): void {
    Route::post('/oa-fixture/admin/coupon', [AdminCouponController::class, 'storeCoupon']);
    Route::post('/oa-fixture/shop/coupon', [ShopCouponController::class, 'storeCoupon']);

    $spec = generateSpec();
    $adminSchema = inlineValidationBodySchema($spec, '/oa-fixture/admin/coupon', 'post');
    $shopSchema = inlineValidationBodySchema($spec, '/oa-fixture/shop/coupon', 'post');

    $adminReference = $spec['paths']['/oa-fixture/admin/coupon']['post']['requestBody']['content']['application/json']['schema']['$ref'];
    $shopReference = $spec['paths']['/oa-fixture/shop/coupon']['post']['requestBody']['content']['application/json']['schema']['$ref'];

    expect($adminReference)->not->toBe($shopReference)
        ->and($adminSchema['properties'])->toHaveKeys(['code', 'discount'])
        ->and($shopSchema['properties'])->toHaveKeys(['code', 'cart_id']);
});

it('omits the method segment from the component key of an invokable controller', function (): void {
    Route::post('/oa-fixture/invokable', InlineValidationInvokableController::class);

    $spec = generateSpec();
    $reference = $spec['paths']['/oa-fixture/invokable']['post']['requestBody']['content']['application/json']['schema']['$ref'];

    expect($reference)->toBe('#/components/schemas/InlineValidationInvokableControllerRequest');
});

// endregion

// region Degradation and GET hand-off

it('degrades to no request body and logs a note when rules are dynamic', function (): void {
    Route::post('/oa-fixture/dynamic', [InlineValidationFixtureController::class, 'dynamicRules']);

    $logger = recordingLogger();
    app()->instance(LoggerInterface::class, $logger);

    $spec = generateSpec();

    expect($spec['paths']['/oa-fixture/dynamic']['post'])->not->toHaveKey('requestBody');

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => str_contains($record['message'], 'could not be read statically'),
    );

    expect($noted)->toBeTrue();
});

it('does not emit a request body for inline validate() on a GET route', function (): void {
    Route::get('/oa-fixture/search', [InlineValidationFixtureController::class, 'search']);

    $spec = generateSpec();

    expect($spec['paths']['/oa-fixture/search']['get'])->not->toHaveKey('requestBody');
});

// endregion
