<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\QueryBuilder;

use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;
use Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderParameterResolver;
use ReflectionClass;
use ReflectionMethod;

class QbResolverController
{
    #[AllowedFilter('status', type: 'string')]
    #[AllowedFilter('priority', type: 'integer')]
    #[AllowedSort(['name', 'created_at'])]
    #[AllowedInclude(['owner'])]
    public function index(): void {}

    public function bare(): void {}
}

function qbDescriptor(string $method): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], '/x', []),
        controller: new ReflectionClass(QbResolverController::class),
        method: new ReflectionMethod(QbResolverController::class, $method),
        summary: null,
        description: null,
    );
}

/** @return list<string> */
function parameterNames(array $parameters): array
{
    return array_map(static fn(OA\Parameter $p): string => $p->name, $parameters);
}

it('emits a filter[...] parameter per #[AllowedFilter]', function (): void {
    $params = (new QueryBuilderParameterResolver())->resolveQueryParameters(qbDescriptor('index'));
    $names = parameterNames($params);

    expect($names)->toContain('filter[status]')
        ->and($names)->toContain('filter[priority]');
});

it('emits a single sort parameter with the allowed fields as enum', function (): void {
    $params = (new QueryBuilderParameterResolver())->resolveQueryParameters(qbDescriptor('index'));

    $sort = null;

    foreach ($params as $p) {
        if ($p->name === 'sort') {
            $sort = $p;
        }
    }

    expect($sort)->not->toBeNull()
        ->and($sort->in)->toBe('query')
        ->and($sort->schema->items->enum)->toBe(['name', 'created_at']);
});

it('emits a single include parameter with the allowed relations as enum', function (): void {
    $params = (new QueryBuilderParameterResolver())->resolveQueryParameters(qbDescriptor('index'));

    $include = null;

    foreach ($params as $p) {
        if ($p->name === 'include') {
            $include = $p;
        }
    }

    expect($include)->not->toBeNull()
        ->and($include->in)->toBe('query')
        ->and($include->schema->items->enum)->toBe(['owner']);
});

it('returns an empty array when no query-builder attributes are present', function (): void {
    expect((new QueryBuilderParameterResolver())->resolveQueryParameters(qbDescriptor('bare')))
        ->toBe([]);
});
