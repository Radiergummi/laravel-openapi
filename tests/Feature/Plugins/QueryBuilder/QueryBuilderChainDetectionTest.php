<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\QueryBuilder;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Attributes\QueryParam as CoreQueryParam;
use Radiergummi\OpenApi\Plugins\ApiResources\ApiResourcesPlugin;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;
use Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderPlugin;
use Radiergummi\OpenApi\Plugins\SpatieData\SpatieDataPlugin;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Author;
use Spatie\QueryBuilder\AllowedFilter as SpatieAllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

use function array_map;

uses()->group('openapi', 'plugin:query-builder');

beforeEach(function (): void {
    config(['openapi.plugins' => [
        SpatieDataPlugin::class,
        ApiResourcesPlugin::class,
        QueryBuilderPlugin::class,
    ]]);
});

// region Fixtures (parse-only; actions are never invoked)

class QbChainOnlyController extends Controller
{
    /** List authors. */
    public function index(): JsonResponse
    {
        $authors = QueryBuilder::for(Author::class)
            ->allowedFilters(['status', SpatieAllowedFilter::exact('origin')])
            ->allowedSorts(['created_at'])
            ->allowedIncludes(['books'])
            ->paginate();

        return new JsonResponse($authors);
    }
}

class QbCoreAttributeCollisionController extends Controller
{
    /** List authors. */
    #[CoreQueryParam('sort', description: 'Author-described sort param.')]
    public function index(): JsonResponse
    {
        $authors = QueryBuilder::for(Author::class)
            ->allowedSorts(['created_at'])
            ->paginate();

        return new JsonResponse($authors);
    }
}

class QbIndirectBuilderController extends Controller
{
    /** List authors. */
    #[AllowedFilter('status', type: 'string')]
    public function index(): JsonResponse
    {
        $query = QueryBuilder::for(Author::class);
        $query->allowedFilters(['name']);

        return new JsonResponse($query->get());
    }

    /** List other authors. */
    public function bare(): JsonResponse
    {
        $query = QueryBuilder::for(Author::class);
        $query->allowedFilters(['name']);

        return new JsonResponse($query->get());
    }
}

class QbPrecedenceController extends Controller
{
    /** List authors. */
    #[AllowedFilter('attribute_filter', type: 'string')]
    #[AllowedSort(['attribute_sort'])]
    public function index(): JsonResponse
    {
        $authors = QueryBuilder::for(Author::class)
            ->allowedFilters(['chain_filter'])
            ->allowedSorts(['chain_sort'])
            ->allowedIncludes(['chain_include'])
            ->get();

        return new JsonResponse($authors);
    }
}

// endregion

/**
 * @param array<int, array<string, mixed>> $parameters
 *
 * @return list<string>
 */
function chainParameterNames(array $parameters): array
{
    return array_map(static fn(array $parameter): string => $parameter['name'], $parameters);
}

it('documents filter, sort, and include parameters from a literal chain without attributes', function (): void {
    Route::get('/qb-chain-authors', [QbChainOnlyController::class, 'index']);

    $spec = generateSpec();
    $parameters = $spec['paths']['/qb-chain-authors']['get']['parameters'] ?? [];
    $names = chainParameterNames($parameters);

    expect($names)->toContain('filter[status]')
        ->and($names)->toContain('filter[origin]')
        ->and($names)->toContain('sort')
        ->and($names)->toContain('include');

    foreach ($parameters as $parameter) {
        expect($parameter['in'])->toBe('query');
    }
});

it('degrades an indirect builder gracefully while still honouring attributes', function (): void {
    Route::get('/qb-indirect-authors', [QbIndirectBuilderController::class, 'index']);
    Route::get('/qb-indirect-bare-authors', [QbIndirectBuilderController::class, 'bare']);

    $spec = generateSpec();

    $attributed = chainParameterNames($spec['paths']['/qb-indirect-authors']['get']['parameters'] ?? []);
    $bare = chainParameterNames($spec['paths']['/qb-indirect-bare-authors']['get']['parameters'] ?? []);

    expect($attributed)->toContain('filter[status]')
        ->and($attributed)->not->toContain('filter[name]')
        ->and($bare)->not->toContain('filter[name]')
        ->and($bare)->not->toContain('sort')
        ->and($bare)->not->toContain('include');
});

it('lets explicit attributes win over the chain per kind', function (): void {
    Route::get('/qb-precedence-authors', [QbPrecedenceController::class, 'index']);

    $spec = generateSpec();
    $parameters = $spec['paths']['/qb-precedence-authors']['get']['parameters'] ?? [];
    $names = chainParameterNames($parameters);

    expect($names)->toContain('filter[attribute_filter]')
        ->and($names)->not->toContain('filter[chain_filter]')
        ->and($names)->toContain('sort')
        ->and($names)->toContain('include');

    foreach ($parameters as $parameter) {
        if ($parameter['name'] === 'sort') {
            expect($parameter['schema']['items']['enum'])->toBe(['attribute_sort']);
        }

        if ($parameter['name'] === 'include') {
            expect($parameter['schema']['items']['enum'])->toBe(['chain_include']);
        }
    }
});

it('keeps an explicit Core #[QueryParam] over a chain-inferred parameter of the same name', function (): void {
    Route::get('/qb-core-attribute-authors', [QbCoreAttributeCollisionController::class, 'index']);

    $spec = generateSpec();
    $parameters = $spec['paths']['/qb-core-attribute-authors']['get']['parameters'] ?? [];

    $sort = null;

    foreach ($parameters as $parameter) {
        if ($parameter['name'] === 'sort' && $parameter['in'] === 'query') {
            expect($sort)->toBeNull();
            $sort = $parameter;
        }
    }

    // The author's attribute (description, untyped/open schema) survives; the chain's
    // canned enum parameter must not replace it (explicit authoring wins, epic #5). The
    // untyped attribute yields an open schema — not a defaulted `type: string`.
    expect($sort)->not->toBeNull()
        ->and($sort['schema']['description'])->toBe('Author-described sort param.')
        ->and($sort['schema'])->not->toHaveKey('type')
        ->and($sort['schema'])->not->toHaveKey('items');
});
