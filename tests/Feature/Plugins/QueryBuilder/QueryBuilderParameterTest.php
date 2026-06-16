<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\QueryBuilder;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Plugins\ApiResources\ApiResourcesPlugin;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;
use Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderPlugin;
use Radiergummi\OpenApi\Plugins\SpatieData\SpatieDataPlugin;

uses()->group('openapi', 'plugin:query-builder');

beforeEach(function (): void {
    config([
        'openapi.plugins' => [
            SpatieDataPlugin::class,
            ApiResourcesPlugin::class,
            QueryBuilderPlugin::class,
        ],
    ]);
});

class QbFixtureController extends Controller
{
    /** List widgets. */
    #[AllowedFilter('status', type: 'string')]
    #[AllowedSort(['name', 'created_at'])]
    #[AllowedInclude(['owner'])]
    public function index(): JsonResponse
    {
        return new JsonResponse([]);
    }
}

it('documents filter, sort, and include query parameters', function (): void {
    Route::get('/qb-widgets', [QbFixtureController::class, 'index']);

    $spec = generateSpec();
    $parameters = $spec['paths']['/qb-widgets']['get']['parameters'] ?? [];

    $names = array_map(static fn(array $p): string => $p['name'], $parameters);

    expect($names)
        ->toContain('filter[status]')
        ->and($names)->toContain('sort')
        ->and($names)->toContain('include');

    foreach ($parameters as $parameter) {
        expect($parameter['in'])->toBe('query');
    }
});
