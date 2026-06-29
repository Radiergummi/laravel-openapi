<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Author;
use Radiergummi\OpenApi\Tests\Fixtures\Resources\NestedAuthorResource;

uses()->group('openapi', 'plugin:api-resources');

/**
 * Actions typing a generic-container return type (`Collection`, Eloquent `Collection`) whose
 * concrete resource lives only in the body. Parse-only; never invoked.
 */
class ContainerReturnTypeController extends Controller
{
    public function plainCollection(): Collection
    {
        return NestedAuthorResource::collection(Author::all());
    }

    public function paginatedCollection(): Collection
    {
        return NestedAuthorResource::collection(Author::query()->paginate());
    }

    public function eloquentCollection(): EloquentCollection
    {
        return NestedAuthorResource::collection(Author::all());
    }

    public function degrade(): Collection
    {
        return $this->random();
    }

    public function paginatorOwned(): LengthAwarePaginator
    {
        return Author::query()->paginate();
    }

    private function random(): Collection
    {
        return Author::all();
    }
}

/**
 * @return array<string, mixed>
 */
function containerResourceSchema(string $path): array
{
    $spec = generateSpec();
    $schema = $spec['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'] ?? null;

    expect($schema)->not->toBeNull();

    return $schema;
}

it('resolves a resource collection from the body behind a Collection return type', function (): void {
    Route::get('/api-container/plain', [ContainerReturnTypeController::class, 'plainCollection']);

    $schema = containerResourceSchema('/api-container/plain');

    expect($schema['properties'])->toHaveKey('data')
        ->and($schema['properties'])->not->toHaveKeys(['links', 'meta'])
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource');
});

it('derives the paginated envelope from a paginating body behind a Collection return type', function (): void {
    Route::get('/api-container/paginated', [ContainerReturnTypeController::class, 'paginatedCollection']);

    $schema = containerResourceSchema('/api-container/paginated');

    expect($schema['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource');
});

it('resolves a resource collection behind an Eloquent Collection return type', function (): void {
    Route::get('/api-container/eloquent', [ContainerReturnTypeController::class, 'eloquentCollection']);

    $schema = containerResourceSchema('/api-container/eloquent');

    expect($schema['properties']['data']['items']['$ref'])->toBe('#/components/schemas/NestedAuthorResource');
});

it('degrades silently to no schema when a container body is not a resource shape', function (): void {
    Route::get('/api-container/degrade', [ContainerReturnTypeController::class, 'degrade']);

    $spec = generateSpec();
    $response = $spec['paths']['/api-container/degrade']['get']['responses']['200'] ?? null;

    expect($response)->not->toBeNull()
        ->and($response['content'] ?? [])->not->toHaveKey('application/json');
});

it('leaves a paginator return type to the paginator response resolver', function (): void {
    Route::get('/api-container/paginator', [ContainerReturnTypeController::class, 'paginatorOwned']);

    // The container gate excludes paginators; the resource factory is not read, so no resource
    // component is contributed by this route (the paginator resolver owns it without an item class).
    $spec = generateSpec();

    expect($spec['components']['schemas'] ?? [])->not->toHaveKey('NestedAuthorResource');
});
