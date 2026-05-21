<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\SpatieData;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\ScalarOnlyData;

uses()->group('openapi', 'plugin:spatie-data');

class DataClassRequestController extends Controller
{
    public function store(ScalarOnlyData $data): JsonResponse
    {
        return new JsonResponse();
    }
}

it('emits an application/json request body $ref for a directly type-hinted Data class', function (): void {
    Route::post('/spatie-data/request', [DataClassRequestController::class, 'store']);

    $spec = generateSpec();

    $body = $spec['paths']['/spatie-data/request']['post']['requestBody'] ?? null;

    expect($body)->not->toBeNull()
        ->and($body['required'])->toBeTrue()
        ->and($body['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/ScalarOnlyData');
});

it('registers the Data class as a component schema', function (): void {
    Route::post('/spatie-data/request', [DataClassRequestController::class, 'store']);

    $spec = generateSpec();

    expect($spec['components']['schemas'])->toHaveKey('ScalarOnlyData');
});
