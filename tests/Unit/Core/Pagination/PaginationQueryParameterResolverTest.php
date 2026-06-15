<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Core\Pagination;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\PaginationQueryParameterResolver;
use Radiergummi\OpenApi\Plugins\Core\Support\PaginatorCallReader;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Author;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

uses()->group('openapi');

/**
 * Parse-only fixture; actions are never invoked.
 */
class PaginationResolverFixtureController
{
    public function offset(): mixed
    {
        return Author::query()->paginate(15);
    }

    public function cursor(): mixed
    {
        return Author::query()->cursorPaginate(15);
    }

    public function plain(): mixed
    {
        return Author::query()->get();
    }
}

/**
 * @return list<OA\Parameter>
 */
function resolvePaginationParameters(string $method): array
{
    $resolver = new PaginationQueryParameterResolver(
        new PaginatorCallReader(new MethodBodyScanner()),
    );

    return $resolver->resolveQueryParameters(
        ActionDescriptorFactory::forControllerMethod(
            PaginationResolverFixtureController::class,
            $method,
        ),
    );
}

it('emits page and per_page for an offset paginator', function (): void {
    $parameters = resolvePaginationParameters('offset');

    expect($parameters)->toHaveCount(2);

    $byName = [];

    foreach ($parameters as $parameter) {
        $byName[$parameter->name] = $parameter;
    }

    expect($byName)->toHaveKeys(['page', 'per_page'])
        ->and($byName['page']->in)->toBe('query')
        ->and($byName['page']->required)->toBeFalse()
        ->and($byName['page']->schema->type)->toBe('integer')
        ->and($byName['page']->schema->minimum)->toBe(1)
        ->and($byName['per_page']->schema->type)->toBe('integer')
        ->and($byName['per_page']->schema->minimum)->toBe(1)
        ->and($byName['per_page']->required)->toBeFalse();
});

it('emits a single cursor parameter for a cursor paginator', function (): void {
    $parameters = resolvePaginationParameters('cursor');

    expect($parameters)->toHaveCount(1)
        ->and($parameters[0]->name)->toBe('cursor')
        ->and($parameters[0]->in)->toBe('query')
        ->and($parameters[0]->required)->toBeFalse()
        ->and($parameters[0]->schema->type)->toBe('string');
});

it('emits nothing for a non-paginating action', function (): void {
    expect(resolvePaginationParameters('plain'))->toBe([]);
});
