<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\EdgeCaseFixtureController;

uses()->group('openapi');

it('emits a oneOf of $refs when the action return type is a union of Data classes', function (): void {
    Route::get('/edge/union-return', [EdgeCaseFixtureController::class, 'unionReturnAction']);

    $spec = generateSpec();

    $schema = $spec['paths']['/edge/union-return']['get']['responses']['200']['content']['application/json']['schema'];

    expect($schema)->toHaveKey('oneOf')
        ->and($schema['oneOf'])->toBe([
            ['$ref' => '#/components/schemas/ScalarOnlyData'],
            ['$ref' => '#/components/schemas/AddressFixtureData'],
        ]);
});

it('ignores non-Data members of a mixed union and emits the Data member as a $ref', function (): void {
    Route::get('/edge/mixed-union', [EdgeCaseFixtureController::class, 'mixedUnionReturnAction']);

    $spec = generateSpec();

    $schema = $spec['paths']['/edge/mixed-union']['get']['responses']['200']['content']['application/json']['schema'];

    expect($schema)->toBe(['$ref' => '#/components/schemas/ScalarOnlyData']);
});
