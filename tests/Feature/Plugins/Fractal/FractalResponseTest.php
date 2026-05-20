<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\Fractal;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Plugins\ApiResources\ApiResourcesPlugin;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin;
use Radiergummi\OpenApi\Plugins\SpatieData\SpatieDataPlugin;
use Symfony\Component\Yaml\Yaml;

uses()->group('openapi', 'plugin:fractal');

beforeEach(function (): void {
    config(['openapi.plugins' => [
        SpatieDataPlugin::class,
        ApiResourcesPlugin::class,
        FractalPlugin::class,
    ]]);
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
}

it('documents a single Fractal response wrapped in data', function (): void {
    Route::get('/books/{book}', [FractalFixtureController::class, 'show']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
    $schema = $spec['paths']['/books/{book}']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKey('data');
});

it('documents a collection Fractal response as a data array', function (): void {
    Route::get('/books', [FractalFixtureController::class, 'index']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
    $schema = $spec['paths']['/books']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema['properties']['data']['type'])->toBe('array')
        ->and($schema['properties'])->not->toHaveKey('meta');
});

it('documents a paginated Fractal response with pagination meta', function (): void {
    Route::get('/books/page', [FractalFixtureController::class, 'paginated']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());
    $schema = $spec['paths']['/books/page']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema['properties']['data']['type'])->toBe('array')
        ->and($schema['properties']['meta']['properties']['pagination']['properties'])
            ->toHaveKeys(['total', 'count', 'per_page', 'current_page', 'total_pages']);
});

it('registers the transformer as a reusable component schema', function (): void {
    Route::get('/books/{book}', [FractalFixtureController::class, 'show']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    expect($spec['components']['schemas'] ?? [])->toHaveKey('BookTransformer');
});
