<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\Fractal;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Plugins\ApiResources\ApiResourcesPlugin;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin;
use Radiergummi\OpenApi\Plugins\SpatieData\SpatieDataPlugin;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\DeclaredAndInferredTransformer;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\DynamicBodyTransformer;
use Radiergummi\OpenApi\Tests\Fixtures\Transformers\InferredArticleTransformer;

uses()->group('openapi', 'plugin:fractal');

beforeEach(function (): void {
    config(['openapi.plugins' => [
        SpatieDataPlugin::class,
        ApiResourcesPlugin::class,
        FractalPlugin::class,
    ]]);
});

class TransformInferenceFixtureController extends Controller
{
    /** Show an article. */
    #[FractalResponse(transformer: InferredArticleTransformer::class)]
    public function show(): JsonResponse
    {
        return new JsonResponse([]);
    }

    /** Show a composed article. */
    #[FractalResponse(transformer: DeclaredAndInferredTransformer::class)]
    public function composed(): JsonResponse
    {
        return new JsonResponse([]);
    }

    /** Show a dynamic article. */
    #[FractalResponse(transformer: DynamicBodyTransformer::class)]
    public function dynamic(): JsonResponse
    {
        return new JsonResponse([]);
    }
}

it('infers the transformer component schema from the transform() literal', function (): void {
    Route::get('/articles/{article}', [TransformInferenceFixtureController::class, 'show']);

    $spec = generateSpec();
    $schema = $spec['components']['schemas']['InferredArticleTransformer'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['type'])->toBe('object')
        ->and(array_keys($schema['properties']))->toBe(
            ['id', 'title', 'published_at', 'word_count', 'price', 'archived', 'kind', 'flags', 'permalink'],
        )
        ->and($schema['properties']['title']['type'])->toBe('string')
        ->and($schema['properties']['word_count']['type'])->toBe('integer')
        ->and($schema['properties']['kind']['type'])->toBe('string')
        ->and($schema['properties']['permalink'])->toBe([])
        ->and($schema['required'])->toContain('id', 'permalink');
});

it('keeps the Fractal envelope around the inferred item schema', function (): void {
    Route::get('/articles/{article}', [TransformInferenceFixtureController::class, 'show']);

    $spec = generateSpec();
    $schema = $spec['paths']['/articles/{article}']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema['properties']['data']['$ref'] ?? null)
        ->toBe('#/components/schemas/InferredArticleTransformer');
});

it('lets a declared #[TransformerField] win per field over the inferred literal', function (): void {
    Route::get('/articles/{article}/composed', [TransformInferenceFixtureController::class, 'composed']);

    $spec = generateSpec();
    $schema = $spec['components']['schemas']['DeclaredAndInferredTransformer'] ?? null;

    expect(array_keys($schema['properties']))->toBe(['id', 'title'])
        ->and($schema['properties']['id']['format'])->toBe('uuid')
        ->and($schema['properties']['title']['type'])->toBe('string');
});

it('degrades a dynamic transform() body to an empty schema', function (): void {
    Route::get('/articles/{article}/dynamic', [TransformInferenceFixtureController::class, 'dynamic']);

    $spec = generateSpec();
    $schema = $spec['components']['schemas']['DynamicBodyTransformer'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['properties'] ?? [])->toBe([]);
});
