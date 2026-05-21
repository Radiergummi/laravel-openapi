<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\SpatieData;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use LogicException;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Tests\Fixtures\ScalarOnlyData;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\PaginatedDataCollection;
use Symfony\Component\Yaml\Yaml;

uses()->group('openapi', 'plugin:spatie-data');

class DataResponseController extends Controller
{
    public function single(): ScalarOnlyData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    /**
     * @return DataCollection<int, ScalarOnlyData>
     */
    public function collection(): DataCollection
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    /**
     * @return PaginatedDataCollection<int, ScalarOnlyData>
     */
    public function paginated(): PaginatedDataCollection
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    /**
     * @return CursorPaginatedDataCollection<int, ScalarOnlyData>
     */
    public function cursorPaginated(): CursorPaginatedDataCollection
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

it('emits a 200 with a $ref for a single Data return type', function (): void {
    Route::get('/spatie-data/single', [DataResponseController::class, 'single']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    $response = $spec['paths']['/spatie-data/single']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['description'])->toBe('OK')
        ->and($response['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/ScalarOnlyData');
});

it('emits an array schema for a DataCollection<X> return type', function (): void {
    Route::get('/spatie-data/collection', [DataResponseController::class, 'collection']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    $schema = $spec['paths']['/spatie-data/collection']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['type'])->toBe('array')
        ->and($schema['items']['$ref'])->toBe('#/components/schemas/ScalarOnlyData');
});

it('emits a length-aware envelope for PaginatedDataCollection<X>', function (): void {
    Route::get('/spatie-data/paginated', [DataResponseController::class, 'paginated']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    $schema = $spec['paths']['/spatie-data/paginated']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKeys(['data', 'per_page', 'current_page', 'last_page', 'total']);
});

it('emits a cursor envelope for CursorPaginatedDataCollection<X>', function (): void {
    Route::get('/spatie-data/cursor', [DataResponseController::class, 'cursorPaginated']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    $schema = $spec['paths']['/spatie-data/cursor']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['properties'])->toHaveKeys(['data', 'next_cursor', 'prev_cursor'])
        ->and($schema['properties'])->not->toHaveKey('current_page');
});
