<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Attributes\ResponseResource;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Author;

uses()->group('openapi');

/** A trivial schema-bearing item used as the paginated element. */
class PaginatedWidget
{
    public int $id = 0;
    public string $name = '';
}

class PaginatorDocblockController extends Controller
{
    /**
     * List widgets.
     *
     * @return LengthAwarePaginatorContract<Author>
     */
    public function index(): LengthAwarePaginatorContract
    {
        return new LengthAwarePaginator([], 0, 15);
    }
}

class PaginatorAttributeController extends Controller
{
    /** List widgets — item type declared by attribute. */
    #[ResponseResource(PaginatedWidget::class, collection: true)]
    public function index(): LengthAwarePaginatorContract
    {
        return new LengthAwarePaginator([], 0, 15);
    }
}

class PaginatorUndeclaredController extends Controller
{
    /** List widgets — no item type anywhere. */
    public function index(): LengthAwarePaginatorContract
    {
        return new LengthAwarePaginator([], 0, 15);
    }
}

class CursorPaginatorController extends Controller
{
    /**
     * Stream widgets.
     *
     * @return CursorPaginatorContract<Author>
     */
    public function index(): CursorPaginatorContract
    {
        return new CursorPaginator([], 15);
    }
}

/**
 * Body-scan fixtures: the `->paginate()`-family call in the body drives the pagination query
 * parameters. Actions are never invoked.
 */
class PaginateBodyController extends Controller
{
    public function offset(): LengthAwarePaginatorContract
    {
        return Author::query()->paginate(15);
    }

    public function cursor(): CursorPaginatorContract
    {
        return Author::query()->cursorPaginate(15);
    }

    #[QueryParam('page', description: 'Authored page knob.')]
    public function authoredPage(): LengthAwarePaginatorContract
    {
        return Author::query()->paginate(15);
    }
}

it('documents a length-aware paginator with the flat envelope', function (): void {
    Route::get('/paginator/docblock', [PaginatorDocblockController::class, 'index']);

    $spec = generateSpec();
    $response = $spec['paths']['/paginator/docblock']['get']['responses']['200'] ?? null;
    $schema = $response['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKeys(['data', 'per_page', 'path', 'current_page', 'from', 'to', 'first_page_url', 'next_page_url', 'prev_page_url', 'last_page', 'last_page_url', 'total', 'links'])
        ->and($schema['properties']['data']['type'])->toBe('array');
});

it('resolves the item type from a #[ResponseResource] attribute', function (): void {
    Route::get('/paginator/attribute', [PaginatorAttributeController::class, 'index']);

    $spec = generateSpec();
    $schema = $spec['paths']['/paginator/attribute']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['properties']['data']['type'])->toBe('array')
        ->and($schema['properties']['data']['items']['type'])->toBe('object');
});

it('falls back to a bare 200 when the paginator item type is undeclared', function (): void {
    Route::get('/paginator/undeclared', [PaginatorUndeclaredController::class, 'index']);

    $spec = generateSpec();
    $response = $spec['paths']['/paginator/undeclared']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['description'] ?? null)->not->toBeNull()
        ->and($response['content'] ?? [])->not->toHaveKey('application/json');
});

it('documents a cursor paginator with cursor metadata', function (): void {
    Route::get('/paginator/cursor', [CursorPaginatorController::class, 'index']);

    $spec = generateSpec();
    $schema = $spec['paths']['/paginator/cursor']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['properties'])->toHaveKeys(['data', 'next_cursor', 'prev_cursor'])
        ->and($schema['properties'])->not->toHaveKey('total');
});

/**
 * @return array<string, array<string, mixed>>
 */
function paginationParametersByName(array $operation): array
{
    $byName = [];

    foreach ($operation['parameters'] ?? [] as $parameter) {
        if (($parameter['in'] ?? null) === 'query') {
            $byName[$parameter['name']] = $parameter;
        }
    }

    return $byName;
}

it('emits page and per_page for an offset-paginating body', function (): void {
    Route::get('/paginate/offset', [PaginateBodyController::class, 'offset']);

    $operation = generateSpec()['paths']['/paginate/offset']['get'];
    $parameters = paginationParametersByName($operation);

    expect($parameters)->toHaveKeys(['page', 'per_page'])
        ->and($parameters)->not->toHaveKey('cursor')
        ->and($parameters['page']['required'])->toBeFalse()
        ->and($parameters['page']['schema']['type'])->toBe('integer')
        ->and($parameters['page']['schema']['minimum'])->toBe(1)
        ->and($parameters['per_page']['schema']['type'])->toBe('integer');
});

it('emits cursor for a cursor-paginating body and omits offset params', function (): void {
    Route::get('/paginate/cursor', [PaginateBodyController::class, 'cursor']);

    $operation = generateSpec()['paths']['/paginate/cursor']['get'];
    $parameters = paginationParametersByName($operation);

    expect($parameters)->toHaveKey('cursor')
        ->and($parameters)->not->toHaveKeys(['page', 'per_page'])
        ->and($parameters['cursor']['schema']['type'])->toBe('string');
});

it('lets an explicit #[QueryParam(page)] win over the inferred page', function (): void {
    Route::get('/paginate/authored', [PaginateBodyController::class, 'authoredPage']);

    $operation = generateSpec()['paths']['/paginate/authored']['get'];
    $pageParameters = array_filter(
        $operation['parameters'] ?? [],
        static fn(array $parameter): bool
            => ($parameter['name'] ?? null) === 'page' && ($parameter['in'] ?? null) === 'query',
    );

    expect($pageParameters)->toHaveCount(1)
        ->and(reset($pageParameters)['schema']['description'] ?? null)->toBe('Authored page knob.');
});
