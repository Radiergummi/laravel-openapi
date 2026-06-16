<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\Fractal;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Plugins\ApiResources\ApiResourcesPlugin;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin;
use Radiergummi\OpenApi\Plugins\Fractal\Support\Serializer;
use Radiergummi\OpenApi\Plugins\SpatieData\SpatieDataPlugin;

uses()->group('openapi', 'plugin:fractal');

beforeEach(function (): void {
    config([
        'openapi.plugins' => [
            SpatieDataPlugin::class,
            ApiResourcesPlugin::class,
            FractalPlugin::class,
        ],
    ]);
});

#[TransformerField('id', type: 'integer')]
#[TransformerField('title', type: 'string')]
class BookTransformer {}

class FractalFixtureController extends Controller
{
    /** Show a book. */
    #[FractalResponse(transformer: BookTransformer::class)]
    public function show(): JsonResponse
    {
        return new JsonResponse([]);
    }

    /** List books. */
    #[FractalResponse(transformer: BookTransformer::class, collection: true)]
    public function index(): JsonResponse
    {
        return new JsonResponse([]);
    }

    /** Paginated books. */
    #[FractalResponse(transformer: BookTransformer::class, paginated: true)]
    public function paginated(): JsonResponse
    {
        return new JsonResponse([]);
    }

    /** ArraySerializer single. */
    #[FractalResponse(transformer: BookTransformer::class, serializer: Serializer::ArraySerializer)]
    public function arraySingle(): JsonResponse
    {
        return new JsonResponse([]);
    }

    /** ArraySerializer collection. */
    #[FractalResponse(transformer: BookTransformer::class, collection: true, serializer: Serializer::ArraySerializer)]
    public function arrayCollection(): JsonResponse
    {
        return new JsonResponse([]);
    }

    /** JsonApi single. */
    #[FractalResponse(transformer: BookTransformer::class, serializer: Serializer::JsonApi)]
    public function jsonApiSingle(): JsonResponse
    {
        return new JsonResponse([]);
    }

    /** JsonApi paginated. */
    #[FractalResponse(transformer: BookTransformer::class, paginated: true, serializer: Serializer::JsonApi)]
    public function jsonApiPaginated(): JsonResponse
    {
        return new JsonResponse([]);
    }
}

it('documents a single Fractal response wrapped in data', function (): void {
    Route::get('/books/{book}', [FractalFixtureController::class, 'show']);

    $spec = generateSpec();
    $schema = $spec['paths']['/books/{book}']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not
        ->toBeNull()
        ->and($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKey('data');
});

it('documents a collection Fractal response as a data array', function (): void {
    Route::get('/books', [FractalFixtureController::class, 'index']);

    $spec = generateSpec();
    $schema = $spec['paths']['/books']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema['properties']['data']['type'])
        ->toBe('array')
        ->and($schema['properties'])->not->toHaveKey('meta');
});

it('documents a paginated Fractal response with pagination meta', function (): void {
    Route::get('/books/page', [FractalFixtureController::class, 'paginated']);

    $spec = generateSpec();
    $schema = $spec['paths']['/books/page']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema['properties']['data']['type'])
        ->toBe('array')
        ->and($schema['properties']['meta']['properties']['pagination']['properties'])
        ->toHaveKeys(['total', 'count', 'per_page', 'current_page', 'total_pages']);
});

it('registers the transformer as a reusable component schema', function (): void {
    Route::get('/books/{book}', [FractalFixtureController::class, 'show']);

    $spec = generateSpec();

    expect($spec['components']['schemas'] ?? [])->toHaveKey('BookTransformer');
});

it('documents an ArraySerializer single response as a bare $ref', function (): void {
    Route::get('/books/{book}/array', [FractalFixtureController::class, 'arraySingle']);

    $spec = generateSpec();
    $schema = $spec['paths']['/books/{book}/array']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not
        ->toBeNull()
        ->and($schema)->toHaveKey('$ref')
        ->and($schema['$ref'])->toBe('#/components/schemas/BookTransformer')
        ->and($schema)->not->toHaveKey('properties');
});

it('documents an ArraySerializer collection as a top-level array', function (): void {
    Route::get('/books/array', [FractalFixtureController::class, 'arrayCollection']);

    $spec = generateSpec();
    $schema = $spec['paths']['/books/array']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema['type'])
        ->toBe('array')
        ->and($schema['items']['$ref'])->toBe('#/components/schemas/BookTransformer');
});

it('documents a JsonApi single under application/vnd.api+json with type/id/attributes', function (): void {
    Route::get('/books/{book}/jsonapi', [FractalFixtureController::class, 'jsonApiSingle']);

    $spec = generateSpec();
    $content = $spec['paths']['/books/{book}/jsonapi']['get']['responses']['200']['content'] ?? [];

    expect($content)
        ->toHaveKey('application/vnd.api+json')
        ->and($content)->not->toHaveKey('application/json');

    $schema = $content['application/vnd.api+json']['schema'];
    $data = $schema['properties']['data'];

    expect(array_keys($data['properties']))
        ->toBe(['type', 'id', 'attributes'])
        ->and($data['properties']['attributes']['$ref'])->toBe('#/components/schemas/BookTransformer');
});

it('documents a JsonApi paginated response with hyphenated pagination keys', function (): void {
    Route::get('/books/jsonapi', [FractalFixtureController::class, 'jsonApiPaginated']);

    $spec = generateSpec();
    $schema = $spec['paths']['/books/jsonapi']['get']['responses']['200']['content']['application/vnd.api+json']['schema'] ?? null;

    expect($schema['properties']['data']['type'])
        ->toBe('array')
        ->and($schema['properties']['data']['items']['properties'])->toHaveKeys(['type', 'id', 'attributes'])
        ->and($schema['properties']['meta']['properties']['pagination']['properties'])
        ->toHaveKeys(['total', 'count', 'per-page', 'current-page', 'total-pages']);
});
