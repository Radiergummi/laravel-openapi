<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Attributes\ResponseResource;

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
     * @return LengthAwarePaginatorContract<PaginatedWidget>
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
     * @return CursorPaginatorContract<PaginatedWidget>
     */
    public function index(): CursorPaginatorContract
    {
        return new CursorPaginator([], 15);
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
