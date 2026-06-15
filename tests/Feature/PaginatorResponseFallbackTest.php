<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Attributes\ResponseResource;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Author;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\NestedAuthorResource;

uses()->group('openapi');

/*
 * Cross-plugin precedence (#353): when Core's PaginatorResponseResolver sees a non-paginator return
 * type whose body paginates, it must NOT claim a response that ApiResources / SpatieData would
 * shape. These fixtures exercise both guard surfaces — the resource/Data return type and the
 * resource-naming #[ResponseResource] attribute — and assert the ApiResources {data,links,meta}
 * envelope lands rather than a Core-emitted response.
 */

class FallbackResourceCollectionController extends Controller
{
    // Bare ResourceCollection return type + a paginating body: ApiResources owns this.
    public function index(): ResourceCollection
    {
        return NestedAuthorResource::collection(Author::query()->paginate());
    }
}

class FallbackAnonymousCollectionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return NestedAuthorResource::collection(Author::query()->paginate());
    }
}

class FallbackResolvableItemCollectionController extends Controller
{
    // A resource return type AND a Core-resolvable item: #[ResponseResource(Author::class)] names a
    // bare model (so Guard 2 passes) and the body paginates — so only Guard 1 (the resource return
    // type) keeps Core from stealing this. Neutralising Guard 1 makes Core claim a {data:[Author]}
    // paginated array instead of the resource envelope ApiResources shapes.
    #[ResponseResource(Author::class)]
    public function index(): AnonymousResourceCollection
    {
        return NestedAuthorResource::collection(Author::query()->paginate());
    }
}

class FallbackMethodAttributeController extends Controller
{
    // A loose JsonResponse return type, but #[ResponseResource] names a JsonResource — ApiResources'
    // claiming surface. Core must defer even though the body paginates.
    #[ResponseResource(NestedAuthorResource::class)]
    public function index(): JsonResponse
    {
        return NestedAuthorResource::collection(Author::query()->paginate())->response();
    }
}

#[ResponseResource(NestedAuthorResource::class)]
class FallbackControllerAttributeController extends Controller
{
    public function index(): JsonResponse
    {
        return NestedAuthorResource::collection(Author::query()->paginate())->response();
    }
}

class FallbackModelItemController extends Controller
{
    // Non-paginator, non-resource return type; #[ResponseResource] names a bare model (not a
    // resource), so both guards pass and Core's body-scan fallback shapes the paginated envelope.
    #[ResponseResource(Author::class)]
    public function index(): JsonResponse
    {
        return new JsonResponse(Author::query()->paginate());
    }
}

class FallbackNoItemController extends Controller
{
    // Non-paginator return type, paginating body, but no declared item class: degrade to bare 200.
    public function index(): JsonResponse
    {
        return new JsonResponse(Author::query()->paginate());
    }
}

it('lets ApiResources shape a paginated ResourceCollection return rather than Core stealing it', function (): void {
    Route::get('/fallback/resource-collection', [FallbackResourceCollectionController::class, 'index']);

    $schema = generateSpec()['paths']['/fallback/resource-collection']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource');
});

it('lets ApiResources shape a paginated AnonymousResourceCollection return rather than Core stealing it', function (): void {
    Route::get('/fallback/anonymous-collection', [FallbackAnonymousCollectionController::class, 'index']);

    $schema = generateSpec()['paths']['/fallback/anonymous-collection']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource');
});

it('keeps ApiResources for a resource return even when the item class is Core-resolvable (Guard 1)', function (): void {
    Route::get('/fallback/resolvable-item', [FallbackResolvableItemCollectionController::class, 'index']);

    $schema = generateSpec()['paths']['/fallback/resolvable-item']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    // Pins Guard 1: the item class is resolvable (#[ResponseResource(Author::class)] is a bare
    // model, so Guard 2 passes and resolveItemClass() would succeed), so only the resource return
    // type stops Core. ApiResources shapes the envelope with the resource $ref and nested
    // pagination meta; without Guard 1 Core would emit a {data: [Author], ...} paginated array.
    expect($schema)->not->toBeNull()
        ->and($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource')
        ->and($schema['properties']['meta']['properties'] ?? null)->toHaveKey('total');
});

it('defers to ApiResources when a method-level #[ResponseResource] names a resource', function (): void {
    Route::get('/fallback/method-attribute', [FallbackMethodAttributeController::class, 'index']);

    $schema = generateSpec()['paths']['/fallback/method-attribute']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    // ApiResources shapes the resource-typed `{data: $ref}` envelope. Core's paginated envelope
    // would instead make `data` an array of items with `links`/`meta` siblings — Guard 2 stops it.
    expect($schema)->not->toBeNull()
        ->and($schema['properties']['data']['$ref'] ?? null)->toBe('#/components/schemas/NestedAuthorResource')
        ->and($schema['properties'])->not->toHaveKeys(['links', 'meta']);
});

it('defers to ApiResources when a controller-level #[ResponseResource] names a resource', function (): void {
    Route::get('/fallback/controller-attribute', [FallbackControllerAttributeController::class, 'index']);

    $schema = generateSpec()['paths']['/fallback/controller-attribute']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull()
        ->and($schema['properties']['data']['$ref'] ?? null)->toBe('#/components/schemas/NestedAuthorResource')
        ->and($schema['properties'])->not->toHaveKeys(['links', 'meta']);
});

it('shapes a paginated envelope from the body scan when the item class is a bare model', function (): void {
    Route::get('/fallback/model-item', [FallbackModelItemController::class, 'index']);

    $schema = generateSpec()['paths']['/fallback/model-item']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    // Length-aware envelope from the unconditional paginate() body, with the model as the item.
    expect($schema)->not->toBeNull()
        ->and($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKeys(['data', 'current_page', 'per_page', 'total', 'links'])
        ->and($schema['properties']['data']['type'])->toBe('array');
});

it('falls back to a bare 200 when no item class is declared even though the body paginates', function (): void {
    Route::get('/fallback/no-item', [FallbackNoItemController::class, 'index']);

    $response = generateSpec()['paths']['/fallback/no-item']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['content'] ?? [])->not->toHaveKey('application/json');
});
