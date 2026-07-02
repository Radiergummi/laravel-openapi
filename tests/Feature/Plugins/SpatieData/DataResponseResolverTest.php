<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\SpatieData;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use LogicException;
use Radiergummi\OpenApi\Tests\Fixtures\AddressFixtureData;
use Radiergummi\OpenApi\Tests\Fixtures\ScalarOnlyData;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\PaginatedDataCollection;

uses()->group('openapi', 'plugin:spatie-data');

class DataResponseController extends Controller
{
    public function single(): ScalarOnlyData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function nullableSingle(): ?ScalarOnlyData
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }

    public function nullableUnion(): ScalarOnlyData|AddressFixtureData|null
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

    $spec = generateSpec();

    $response = $spec['paths']['/spatie-data/single']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['description'])->toBe('OK')
        ->and($response['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/ScalarOnlyData');
});

it('wraps a nullable single Data return in an OAS 3.1 nullable $ref', function (): void {
    Route::get('/spatie-data/nullable-single', [DataResponseController::class, 'nullableSingle']);

    $spec = generateSpec();

    $schema = $spec['paths']['/spatie-data/nullable-single']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    // OAS 3.1 forbids a sibling keyword next to $ref, so nullability is the oneOf/null idiom, not a
    // bare $ref (the pre-consolidation output silently dropped the nullability).
    expect($schema)->not->toBeNull()
        ->and($schema)->not->toHaveKey('$ref')
        ->and($schema['oneOf'])->toBe([
            ['$ref' => '#/components/schemas/ScalarOnlyData'],
            ['type' => 'null'],
        ]);
});

it('wraps a nullable Data union in a nullable oneOf', function (): void {
    Route::get('/spatie-data/nullable-union', [DataResponseController::class, 'nullableUnion']);

    $spec = generateSpec();

    $schema = $spec['paths']['/spatie-data/nullable-union']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    // The union's oneOf is wrapped by the nullable idiom; the null member is no longer dropped.
    expect($schema)->not->toBeNull()
        ->and($schema['oneOf'])->toHaveCount(2)
        ->and($schema['oneOf'][0]['oneOf'] ?? null)->toBe([
            ['$ref' => '#/components/schemas/ScalarOnlyData'],
            ['$ref' => '#/components/schemas/AddressFixtureData'],
        ])
        ->and($schema['oneOf'][1])->toBe(['type' => 'null']);
});

it('emits an array schema for a DataCollection<X> return type', function (): void {
    Route::get('/spatie-data/collection', [DataResponseController::class, 'collection']);

    $spec = generateSpec();

    $schema = $spec['paths']['/spatie-data/collection']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['type'])->toBe('array')
        ->and($schema['items']['$ref'])->toBe('#/components/schemas/ScalarOnlyData');
});

it('emits a length-aware envelope for PaginatedDataCollection<X>', function (): void {
    Route::get('/spatie-data/paginated', [DataResponseController::class, 'paginated']);

    $spec = generateSpec();

    $schema = $spec['paths']['/spatie-data/paginated']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKeys(['data', 'per_page', 'current_page', 'last_page', 'total']);
});

it('emits a cursor envelope for CursorPaginatedDataCollection<X>', function (): void {
    Route::get('/spatie-data/cursor', [DataResponseController::class, 'cursorPaginated']);

    $spec = generateSpec();

    $schema = $spec['paths']['/spatie-data/cursor']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['properties'])->toHaveKeys(['data', 'next_cursor', 'prev_cursor'])
        ->and($schema['properties'])->not->toHaveKey('current_page');
});
