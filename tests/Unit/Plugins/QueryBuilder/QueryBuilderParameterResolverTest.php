<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\QueryBuilder;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;
use Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderParameterResolver;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

use function array_find;

class QbResolverController
{
    #[AllowedFilter('status', type: 'string')]
    #[AllowedFilter('priority', type: 'integer')]
    #[AllowedSort(['name', 'created_at'])]
    #[AllowedInclude(['owner'])]
    public function index(): void {}

    public function bare(): void {}
}

/** @return list<string> */
function parameterNames(array $parameters): array
{
    return array_map(static fn(OA\Parameter $p): string => $p->name, $parameters);
}

it('emits a filter[...] parameter per #[AllowedFilter]', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(QbResolverController::class, 'index');
    $params = new QueryBuilderParameterResolver()->resolveQueryParameters($descriptor);

    expect(parameterNames($params))->toContain('filter[status]')
        ->and(parameterNames($params))->toContain('filter[priority]');
});

it('emits a single sort parameter with the allowed fields as enum', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(QbResolverController::class, 'index');
    $params = new QueryBuilderParameterResolver()->resolveQueryParameters($descriptor);

    $sort = array_find($params, static fn(OA\Parameter $p): bool => $p->name === 'sort');

    expect($sort)->not->toBeNull()
        ->and($sort->in)->toBe('query')
        ->and($sort->schema->items->enum)->toBe(['name', 'created_at']);
});

it('emits a single include parameter with the allowed relations as enum', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(QbResolverController::class, 'index');
    $params = new QueryBuilderParameterResolver()->resolveQueryParameters($descriptor);

    $include = array_find($params, static fn(OA\Parameter $p): bool => $p->name === 'include');

    expect($include)->not->toBeNull()
        ->and($include->in)->toBe('query')
        ->and($include->schema->items->enum)->toBe(['owner']);
});

it('returns an empty array when no query-builder attributes are present', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(QbResolverController::class, 'bare');

    expect(new QueryBuilderParameterResolver()->resolveQueryParameters($descriptor))
        ->toBe([]);
});

class QbNullableFilterController
{
    #[AllowedFilter('cursor', type: 'string', nullable: true, minimum: 1, maximum: 100)]
    public function index(): void {}
}

it('widens a nullable AllowedFilter schema to the [type, null] shape and forwards numeric bounds', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(QbNullableFilterController::class, 'index');
    $params = new QueryBuilderParameterResolver()->resolveQueryParameters($descriptor);

    $cursor = array_find($params, static fn(OA\Parameter $p): bool => $p->name === 'filter[cursor]');

    expect($cursor)->not->toBeNull()
        ->and($cursor->schema->type)->toBe(['string', 'null'])
        ->and($cursor->schema->minimum)->toBe(1)
        ->and($cursor->schema->maximum)->toBe(100);
});
