<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\QueryBuilder;

use Illuminate\Http\JsonResponse;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Resolvers\QueryBuilderParameterResolver;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Support\QueryBuilderChainReader;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Author;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Spatie\QueryBuilder\AllowedFilter as SpatieAllowedFilter;
use Spatie\QueryBuilder\AllowedInclude as SpatieAllowedInclude;
use Spatie\QueryBuilder\AllowedSort as SpatieAllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

use function array_find;
use function array_map;
use function implode;

// region Helpers

function makeQueryBuilderParameterResolver(?LoggerInterface $logger = null): QueryBuilderParameterResolver
{
    return new QueryBuilderParameterResolver(
        chainReader: new QueryBuilderChainReader(new MethodBodyScanner()),
        logger: $logger ?? new NullLogger(),
    );
}

/**
 * @param class-string $controller
 */
function chainDescriptor(string $controller, string $method): ActionDescriptor
{
    return ActionDescriptorFactory::forControllerMethod($controller, $method);
}

/**
 * @param list<OA\Parameter> $parameters
 *
 * @return list<string>
 */
function parameterNames(array $parameters): array
{
    return array_map(static fn(OA\Parameter $p): string => $p->name, $parameters);
}

// endregion

// region Attribute fixtures

class QbResolverController
{
    #[AllowedFilter('status', type: 'string')]
    #[AllowedFilter('priority', type: 'integer')]
    #[AllowedSort(['name', 'created_at'])]
    #[AllowedInclude(['owner'])]
    public function index(): void {}

    public function bare(): void {}
}

class QbNullableFilterController
{
    #[AllowedFilter('cursor', type: 'string', nullable: true, minimum: 1, maximum: 100)]
    public function index(): void {}
}

// endregion

// region Chain fixtures (parse-only; actions are never invoked)

/**
 * A non-Spatie class that happens to share the `QueryBuilder` shape — its chain must never match.
 */
final class QbImpostorQueryBuilder
{
    public static function for(string $subject): self
    {
        return new self();
    }

    /**
     * @param list<string> $filters
     */
    public function allowedFilters(array $filters): self
    {
        return $this;
    }

    /**
     * @return list<string>
     */
    public function get(): array
    {
        return [];
    }
}

class QbChainFixtureController
{
    /** @var list<string> */
    private array $dynamicFilters = ['a'];

    private string $dynamicName = 'a';

    public function literalChain(): mixed
    {
        return QueryBuilder::for(Author::class)
            ->allowedFilters(['status', 'origin'])
            ->allowedSorts(['departs_at', '-number'])
            ->allowedIncludes(['bookings'])
            ->defaultSort('-departs_at')
            ->paginate();
    }

    public function valueObjectChain(): mixed
    {
        return QueryBuilder::for(Author::class)
            ->allowedFilters([
                SpatieAllowedFilter::exact('status'),
                SpatieAllowedFilter::partial(name: 'name'),
                SpatieAllowedFilter::scope('starts_before'),
                SpatieAllowedFilter::trashed(),
            ])
            ->allowedSorts([SpatieAllowedSort::field('-created_at', 'internal_column')])
            ->allowedIncludes([SpatieAllowedInclude::relationship('owner')])
            ->get();
    }

    public function assignedChain(): JsonResponse
    {
        $page = QueryBuilder::for(Author::class)
            ->allowedFilters(['status'])
            ->paginate();

        return new JsonResponse($page);
    }

    public function variadicChain(): mixed
    {
        return QueryBuilder::for(Author::class)
            ->allowedSorts('name', 'created_at')
            ->get();
    }

    public function partiallyReadableChain(): mixed
    {
        return QueryBuilder::for(Author::class)
            ->allowedFilters(['status', $this->dynamicName, SpatieAllowedFilter::exact($this->dynamicName)])
            ->get();
    }

    public function crossKindValueObjectChain(): mixed
    {
        return QueryBuilder::for(Author::class)
            ->allowedFilters([SpatieAllowedSort::field('status'), 'origin'])
            ->get();
    }

    public function dynamicAllowListChain(): mixed
    {
        return QueryBuilder::for(Author::class)
            ->allowedFilters($this->dynamicFilters)
            ->get();
    }

    public function mutatedBuilder(): mixed
    {
        $query = QueryBuilder::for(Author::class);
        $query->allowedFilters(['status']);

        return $query->get();
    }

    public function conditionalChain(bool $flag): mixed
    {
        if ($flag) {
            return QueryBuilder::for(Author::class)->allowedFilters(['status'])->get();
        }

        return [];
    }

    public function impostorChain(): mixed
    {
        return QbImpostorQueryBuilder::for(Author::class)
            ->allowedFilters(['status'])
            ->get();
    }

    public function lockedDownBuilder(): mixed
    {
        return QueryBuilder::for(Author::class)->paginate();
    }

    public function chainBeyondScanWindow(): mixed
    {
        $one = 1;
        $two = 2;
        $three = 3;
        $four = 4;
        $five = 5;
        $six = 6;
        $seven = 7;
        $eight = 8;
        $nine = 9;
        $ten = $one + $two + $three + $four + $five + $six + $seven + $eight + $nine;

        return QueryBuilder::for(Author::class)->allowedFilters(['status'])->get() ?: $ten;
    }

    #[AllowedFilter('attribute_filter', type: 'string')]
    public function attributedFilterWithChain(): mixed
    {
        return QueryBuilder::for(Author::class)
            ->allowedFilters(['chain_filter'])
            ->allowedSorts(['chain_sort'])
            ->get();
    }

    #[AllowedFilter('attribute_filter', type: 'string')]
    #[AllowedSort(['attribute_sort'])]
    #[AllowedInclude(['attribute_include'])]
    public function fullyAttributedWithDynamicChain(): mixed
    {
        return QueryBuilder::for(Author::class)
            ->allowedFilters($this->dynamicFilters)
            ->get();
    }
}

// endregion

// region Attribute-driven resolution

it('emits a filter[...] parameter per #[AllowedFilter]', function (): void {
    $descriptor = chainDescriptor(QbResolverController::class, 'index');
    $params = makeQueryBuilderParameterResolver()->resolveQueryParameters($descriptor);

    expect(parameterNames($params))->toContain('filter[status]')
        ->and(parameterNames($params))->toContain('filter[priority]');
});

it('emits a single sort parameter with the allowed fields as enum', function (): void {
    $descriptor = chainDescriptor(QbResolverController::class, 'index');
    $params = makeQueryBuilderParameterResolver()->resolveQueryParameters($descriptor);

    $sort = array_find($params, static fn(OA\Parameter $p): bool => $p->name === 'sort');

    expect($sort)->not->toBeNull()
        ->and($sort->in)->toBe('query')
        ->and($sort->schema->items->enum)->toBe(['name', 'created_at']);
});

it('emits a single include parameter with the allowed relations as enum', function (): void {
    $descriptor = chainDescriptor(QbResolverController::class, 'index');
    $params = makeQueryBuilderParameterResolver()->resolveQueryParameters($descriptor);

    $include = array_find($params, static fn(OA\Parameter $p): bool => $p->name === 'include');

    expect($include)->not->toBeNull()
        ->and($include->in)->toBe('query')
        ->and($include->schema->items->enum)->toBe(['owner']);
});

it('returns an empty array when no query-builder attributes or chains are present', function (): void {
    $descriptor = chainDescriptor(QbResolverController::class, 'bare');

    expect(makeQueryBuilderParameterResolver()->resolveQueryParameters($descriptor))
        ->toBe([]);
});

it('widens a nullable AllowedFilter schema to the [type, null] shape and forwards numeric bounds', function (): void {
    $descriptor = chainDescriptor(QbNullableFilterController::class, 'index');
    $params = makeQueryBuilderParameterResolver()->resolveQueryParameters($descriptor);

    $cursor = array_find($params, static fn(OA\Parameter $p): bool => $p->name === 'filter[cursor]');

    expect($cursor)->not->toBeNull()
        ->and($cursor->schema->type)->toBe(['string', 'null'])
        ->and($cursor->schema->minimum)->toBe(1)
        ->and($cursor->schema->maximum)->toBe(100);
});

// endregion

// region Chain detection

it('documents filter, sort, and include parameters from a literal QueryBuilder::for chain', function (): void {
    $descriptor = chainDescriptor(QbChainFixtureController::class, 'literalChain');
    $params = makeQueryBuilderParameterResolver()->resolveQueryParameters($descriptor);

    $sort = array_find($params, static fn(OA\Parameter $p): bool => $p->name === 'sort');
    $include = array_find($params, static fn(OA\Parameter $p): bool => $p->name === 'include');

    expect(parameterNames($params))->toBe(['filter[status]', 'filter[origin]', 'sort', 'include'])
        ->and($sort->schema->items->enum)->toBe(['departs_at', 'number'])
        ->and($include->schema->items->enum)->toBe(['bookings']);
});

it('defaults a chain-derived filter parameter schema to string', function (): void {
    $descriptor = chainDescriptor(QbChainFixtureController::class, 'literalChain');
    $params = makeQueryBuilderParameterResolver()->resolveQueryParameters($descriptor);

    $filter = array_find($params, static fn(OA\Parameter $p): bool => $p->name === 'filter[status]');

    expect($filter->in)->toBe('query')
        ->and($filter->required)->toBeFalse()
        ->and($filter->schema->type)->toBe('string');
});

it('reads the wire name from Spatie value-object constructors', function (): void {
    $descriptor = chainDescriptor(QbChainFixtureController::class, 'valueObjectChain');
    $params = makeQueryBuilderParameterResolver()->resolveQueryParameters($descriptor);

    $sort = array_find($params, static fn(OA\Parameter $p): bool => $p->name === 'sort');
    $include = array_find($params, static fn(OA\Parameter $p): bool => $p->name === 'include');

    expect(parameterNames($params))->toContain('filter[status]')
        ->and(parameterNames($params))->toContain('filter[name]')
        ->and(parameterNames($params))->toContain('filter[starts_before]')
        ->and(parameterNames($params))->toContain('filter[trashed]')
        ->and($sort->schema->items->enum)->toBe(['created_at'])
        ->and($include->schema->items->enum)->toBe(['owner']);
});

it('reads a chain assigned to a variable in a single expression', function (): void {
    $descriptor = chainDescriptor(QbChainFixtureController::class, 'assignedChain');
    $params = makeQueryBuilderParameterResolver()->resolveQueryParameters($descriptor);

    expect(parameterNames($params))->toBe(['filter[status]']);
});

it('reads the variadic allow-list form', function (): void {
    $descriptor = chainDescriptor(QbChainFixtureController::class, 'variadicChain');
    $params = makeQueryBuilderParameterResolver()->resolveQueryParameters($descriptor);

    $sort = array_find($params, static fn(OA\Parameter $p): bool => $p->name === 'sort');

    expect($sort)->not->toBeNull()
        ->and($sort->schema->items->enum)->toBe(['name', 'created_at']);
});

// endregion

// region Graceful degradation

it('keeps readable elements and drops non-literal ones with a notice', function (): void {
    $logger = recordingLogger();
    $descriptor = chainDescriptor(QbChainFixtureController::class, 'partiallyReadableChain');
    $params = makeQueryBuilderParameterResolver($logger)->resolveQueryParameters($descriptor);

    expect(parameterNames($params))->toBe(['filter[status]'])
        ->and(implode("\n", array_map(static fn(array $r): string => $r['message'], $logger->records)))
        ->toContain('allowedFilters');
});

it('drops a cross-kind value object as unreadable but keeps the rest', function (): void {
    $descriptor = chainDescriptor(QbChainFixtureController::class, 'crossKindValueObjectChain');
    $params = makeQueryBuilderParameterResolver()->resolveQueryParameters($descriptor);

    expect(parameterNames($params))->toBe(['filter[origin]']);
});

it('degrades a dynamic allow-list to no parameters with a notice', function (): void {
    $logger = recordingLogger();
    $descriptor = chainDescriptor(QbChainFixtureController::class, 'dynamicAllowListChain');
    $params = makeQueryBuilderParameterResolver($logger)->resolveQueryParameters($descriptor);

    expect($params)->toBe([])
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('could not be read statically');
});

it('refuses a builder assigned to a variable and mutated across statements', function (): void {
    $logger = recordingLogger();
    $descriptor = chainDescriptor(QbChainFixtureController::class, 'mutatedBuilder');
    $params = makeQueryBuilderParameterResolver($logger)->resolveQueryParameters($descriptor);

    expect($params)->toBe([])
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('could not be read statically');
});

it('refuses a chain inside a conditional context', function (): void {
    $logger = recordingLogger();
    $descriptor = chainDescriptor(QbChainFixtureController::class, 'conditionalChain');
    $params = makeQueryBuilderParameterResolver($logger)->resolveQueryParameters($descriptor);

    expect($params)->toBe([])
        ->and($logger->records)->toHaveCount(1);
});

it('never matches an impostor QueryBuilder class', function (): void {
    $descriptor = chainDescriptor(QbChainFixtureController::class, 'impostorChain');
    $params = makeQueryBuilderParameterResolver()->resolveQueryParameters($descriptor);

    expect($params)->toBe([]);
});

it('stays silent for a builder with no allowed-list calls at all', function (): void {
    $logger = recordingLogger();
    $descriptor = chainDescriptor(QbChainFixtureController::class, 'lockedDownBuilder');
    $params = makeQueryBuilderParameterResolver($logger)->resolveQueryParameters($descriptor);

    expect($params)->toBe([])
        ->and($logger->records)->toBe([]);
});

it('does not look past the first ten statements', function (): void {
    $logger = recordingLogger();
    $descriptor = chainDescriptor(QbChainFixtureController::class, 'chainBeyondScanWindow');
    $params = makeQueryBuilderParameterResolver($logger)->resolveQueryParameters($descriptor);

    expect($params)->toBe([])
        ->and($logger->records)->toBe([]);
});

// endregion

// region Attribute precedence

it('lets attributes win per kind while the chain fills attribute-less kinds', function (): void {
    $descriptor = chainDescriptor(QbChainFixtureController::class, 'attributedFilterWithChain');
    $params = makeQueryBuilderParameterResolver()->resolveQueryParameters($descriptor);

    $sort = array_find($params, static fn(OA\Parameter $p): bool => $p->name === 'sort');

    expect(parameterNames($params))->toBe(['filter[attribute_filter]', 'sort'])
        ->and($sort->schema->items->enum)->toBe(['chain_sort']);
});

it('skips the body scan entirely when every kind is attribute-covered', function (): void {
    $logger = recordingLogger();
    $descriptor = chainDescriptor(QbChainFixtureController::class, 'fullyAttributedWithDynamicChain');
    $params = makeQueryBuilderParameterResolver($logger)->resolveQueryParameters($descriptor);

    expect(parameterNames($params))->toBe(['filter[attribute_filter]', 'sort', 'include'])
        ->and($logger->records)->toBe([]);
});

// endregion
