<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins;

use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Plugins\ApiResources\ApiResourcesPlugin;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;
use Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;
use Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderPlugin;
use Radiergummi\OpenApi\Plugins\SpatieData\SpatieDataPlugin;

use function array_map;

uses()->group('openapi', 'plugin:suite');


class SuiteWidget
{
    public int $id = 0;

    public string $name = '';
}

#[ResourceField('id', type: 'integer')]
#[ResourceField('name', type: 'string')]
class SuiteWidgetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        /** @var SuiteWidget $widget */
        $widget = $this->resource;

        return [
            'id' => $widget->id,
            'name' => $widget->name,
        ];
    }
}

#[TransformerField('id', type: 'integer')]
#[TransformerField('label', type: 'string')]
#[TransformerInclude('owner', transformer: SuiteOwnerTransformer::class)]
class SuiteWidgetTransformer {}

#[TransformerField('id', type: 'integer')]
#[TransformerField('email', type: 'string')]
class SuiteOwnerTransformer {}

class PluginSuiteController extends Controller
{
    /**
     * List widgets — paginator core resolver.
     *
     * @return LengthAwarePaginatorContract<SuiteWidget>
     */
    #[AllowedFilter('status', type: 'string')]
    #[AllowedSort(['name'])]
    #[AllowedInclude(['owner'])]
    public function paginated(): LengthAwarePaginatorContract
    {
        return new LengthAwarePaginator([], 0, 15);
    }

    /** Show a widget — ApiResources plugin. */
    public function resource(): SuiteWidgetResource
    {
        return new SuiteWidgetResource(new SuiteWidget());
    }

    /** Show a widget — Fractal plugin (single envelope). */
    #[FractalResponse(transformer: SuiteWidgetTransformer::class)]
    public function fractalSingle(): JsonResponse
    {
        return new JsonResponse([]);
    }

    /** List widgets — Fractal plugin (collection envelope). */
    #[FractalResponse(transformer: SuiteWidgetTransformer::class, collection: true)]
    public function fractalCollection(): JsonResponse
    {
        return new JsonResponse([]);
    }

    /** Paginated widgets — Fractal plugin (paginated envelope). */
    #[FractalResponse(transformer: SuiteWidgetTransformer::class, paginated: true)]
    public function fractalPaginated(): JsonResponse
    {
        return new JsonResponse([]);
    }
}

beforeEach(function (): void {
    config(['openapi.plugins' => [
        SpatieDataPlugin::class,
        ApiResourcesPlugin::class,
        QueryBuilderPlugin::class,
        FractalPlugin::class,
    ]]);

    Route::get('/suite/paginated', [PluginSuiteController::class, 'paginated']);
    Route::get('/suite/resource', [PluginSuiteController::class, 'resource']);
    Route::get('/suite/fractal-single', [PluginSuiteController::class, 'fractalSingle']);
    Route::get('/suite/fractal-collection', [PluginSuiteController::class, 'fractalCollection']);
    Route::get('/suite/fractal-paginated', [PluginSuiteController::class, 'fractalPaginated']);

    $this->spec = generateSpec();
});

function suiteSpec(): array
{
    return test()->spec;
}

function suiteParamNames(array $operation): array
{
    return array_map(static fn(array $p): string => $p['name'], $operation['parameters'] ?? []);
}

it('renders the paginator route with the core flat envelope and QueryBuilder params (but no Fractal envelope)', function (): void {
    $paginated = suiteSpec()['paths']['/suite/paginated']['get'];

    // Core paginator envelope: flat with data + total, not Fractal's {data, meta: {pagination}}.
    $schema = $paginated['responses']['200']['content']['application/json']['schema'];
    expect($schema['properties'])
        ->toHaveKey('data')
        ->toHaveKey('total')
        ->toHaveKey('per_page')
        ->toHaveKey('current_page');
    expect($schema['properties'])->not->toHaveKey('meta'); // would be Fractal's pagination meta shape

    // QueryBuilder plugin contributes filter[status] + sort + include onto this operation.
    expect(suiteParamNames($paginated))
        ->toContain('filter[status]')
        ->toContain('sort')
        ->toContain('include');
});

it('renders the resource route with the ApiResources envelope and zero QueryBuilder params', function (): void {
    $resource = suiteSpec()['paths']['/suite/resource']['get'];

    // ApiResources single envelope: {data: $ref}.
    expect($resource['responses']['200']['content']['application/json']['schema']['properties'])
        ->toHaveKey('data');

    // The plugin contributes a component schema with the declared #[ResourceField]s.
    $components = suiteSpec()['components']['schemas'];
    expect($components)->toHaveKey('SuiteWidgetResource');
    expect($components['SuiteWidgetResource']['properties'])
        ->toHaveKey('id')
        ->toHaveKey('name');

    // The resource route must NOT carry filter[*] or sort — the resource action has no
    // QueryBuilder attributes, and the plugin must not leak siblings' params onto it.
    $params = suiteParamNames($resource);
    expect($params)->not->toContain('filter[status]')
        ->not->toContain('sort')
        ->not->toContain('include');
});

it('renders all three Fractal envelope shapes (single / collection / paginated)', function (): void {
    $spec = suiteSpec();

    $single = $spec['paths']['/suite/fractal-single']['get']['responses']['200']['content']['application/json']['schema'];
    $collection = $spec['paths']['/suite/fractal-collection']['get']['responses']['200']['content']['application/json']['schema'];
    $paginated = $spec['paths']['/suite/fractal-paginated']['get']['responses']['200']['content']['application/json']['schema'];

    // Single: {data: $ref-to-transformer-schema}, no array.
    expect($single['properties'])->toHaveKey('data');
    expect($single['properties']['data'])->not->toHaveKey('type'); // ref, not array

    // Collection: {data: [array of refs]}, no pagination meta.
    expect($collection['properties']['data'])->toHaveKey('type');
    expect($collection['properties']['data']['type'])->toBe('array');
    expect($collection['properties'])->not->toHaveKey('meta');

    // Paginated: {data: [...], meta: {pagination: {…}}}.
    expect($paginated['properties'])->toHaveKey('data')->toHaveKey('meta');
    expect($paginated['properties']['data']['type'])->toBe('array');
    expect($paginated['properties']['meta']['properties'])->toHaveKey('pagination');
});

it('registers the included transformer as its own component schema', function (): void {
    $components = suiteSpec()['components']['schemas'];

    expect($components)->toHaveKey('SuiteWidgetTransformer');
    expect($components['SuiteWidgetTransformer']['properties'])
        ->toHaveKey('id')
        ->toHaveKey('label')
        ->toHaveKey('owner');

    // The include's transformer was registered as its own component schema via the
    // TransformerRefSchemaResolver chain (verifies cross-include ref resolution).
    expect($components)->toHaveKey('SuiteOwnerTransformer');
    expect($components['SuiteOwnerTransformer']['properties'])
        ->toHaveKey('id')
        ->toHaveKey('email');
});

it('keeps Fractal routes free of QueryBuilder query parameters', function (): void {
    $spec = suiteSpec();

    foreach (['/suite/fractal-single', '/suite/fractal-collection', '/suite/fractal-paginated'] as $path) {
        expect(suiteParamNames($spec['paths'][$path]['get']))
            ->not->toContain('filter[status]')
            ->not->toContain('sort')
            ->not->toContain('include');
    }
});
