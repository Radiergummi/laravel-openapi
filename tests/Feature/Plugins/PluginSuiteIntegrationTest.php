<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins;

use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Plugins\ApiResources\ApiResourcesPlugin;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;
use Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderPlugin;
use Radiergummi\OpenApi\Plugins\SpatieData\SpatieDataPlugin;
use Symfony\Component\Yaml\Yaml;

uses()->group('openapi', 'plugin:suite');

beforeEach(function (): void {
    config(['openapi.plugins' => [
        SpatieDataPlugin::class,
        ApiResourcesPlugin::class,
        QueryBuilderPlugin::class,
        FractalPlugin::class,
    ]]);
});

#[ResourceField('id', type: 'integer')]
#[ResourceField('name', type: 'string')]
class SuiteWidgetResource extends JsonResource {}

#[TransformerField('id', type: 'integer')]
#[TransformerField('label', type: 'string')]
class SuiteWidgetTransformer {}

class SuiteWidget {}

class PluginSuiteController extends Controller
{
    /**
     * List widgets — paginator core resolver.
     *
     * @return LengthAwarePaginatorContract<SuiteWidget>
     */
    #[AllowedFilter('status', type: 'string')]
    #[AllowedSort(['name'])]
    public function paginated(): LengthAwarePaginatorContract
    {
        return new LengthAwarePaginator([], 0, 15);
    }

    /** Show a widget — ApiResources plugin. */
    public function resource(): SuiteWidgetResource
    {
        return new SuiteWidgetResource(null);
    }

    /** Show a widget — Fractal plugin. */
    #[FractalResponse(transformer: SuiteWidgetTransformer::class)]
    public function fractal(): JsonResponse
    {
        return new JsonResponse([]);
    }
}

it('generates one document with every plugin active', function (): void {
    Route::get('/suite/paginated', [PluginSuiteController::class, 'paginated']);
    Route::get('/suite/resource', [PluginSuiteController::class, 'resource']);
    Route::get('/suite/fractal', [PluginSuiteController::class, 'fractal']);

    $spec = Yaml::parse(app(OpenApiGenerator::class)->generate()->toYaml());

    // Paginator core resolver produced the flat envelope.
    $paginated = $spec['paths']['/suite/paginated']['get'];
    expect($paginated['responses']['200']['content']['application/json']['schema']['properties'])
        ->toHaveKey('data')
        ->toHaveKey('total');

    // QueryBuilder plugin contributed query parameters onto the same operation.
    $paramNames = array_map(static fn(array $p): string => $p['name'], $paginated['parameters'] ?? []);
    expect($paramNames)->toContain('filter[status]')->toContain('sort');

    // ApiResources plugin produced the {data} envelope and a component schema.
    expect($spec['paths']['/suite/resource']['get']['responses']['200']['content']['application/json']['schema']['properties'])
        ->toHaveKey('data')
        ->and($spec['components']['schemas'])->toHaveKey('SuiteWidgetResource');

    // Fractal plugin produced its response and component schema.
    expect($spec['paths']['/suite/fractal']['get']['responses']['200']['content']['application/json']['schema']['properties'])
        ->toHaveKey('data')
        ->and($spec['components']['schemas'])->toHaveKey('SuiteWidgetTransformer');
});
