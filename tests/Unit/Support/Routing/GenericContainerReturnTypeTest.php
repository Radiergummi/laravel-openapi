<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Routing;

use Closure;
use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;
use LogicException;
use Radiergummi\OpenApi\Support\Routing\GenericContainerReturnType;
use Radiergummi\OpenApi\Tests\Fixtures\ScalarOnlyData;
use ReflectionFunction;
use ReflectionType;

uses()->group('openapi');

/** Reflects a closure's declared return type. */
function returnTypeOf(Closure $closure): ?ReflectionType
{
    return new ReflectionFunction($closure)->getReturnType();
}

it('matches builtin array', function (): void {
    expect(GenericContainerReturnType::matches(returnTypeOf(fn(): array => [])))->toBeTrue();
});

it('matches Enumerable collection types', function (): void {
    expect(GenericContainerReturnType::matches(returnTypeOf(fn(): Collection => new Collection())))
        ->toBeTrue()
        ->and(GenericContainerReturnType::matches(returnTypeOf(fn(): EloquentCollection => new EloquentCollection())))
        ->toBeTrue()
        ->and(GenericContainerReturnType::matches(returnTypeOf(fn(): LazyCollection => new LazyCollection())))
        ->toBeTrue()
        ->and(GenericContainerReturnType::matches(returnTypeOf(fn(): Enumerable => new Collection())))
        ->toBeTrue();
});

it('does not match paginator return types', function (): void {
    expect(GenericContainerReturnType::matches(returnTypeOf(fn(): LengthAwarePaginatorContract => throw new LogicException())))
        ->toBeFalse()
        ->and(GenericContainerReturnType::matches(returnTypeOf(fn(): PaginatorContract => throw new LogicException())))
        ->toBeFalse()
        ->and(GenericContainerReturnType::matches(returnTypeOf(fn(): CursorPaginatorContract => throw new LogicException())))
        ->toBeFalse();
});

it('does not match the concrete LengthAwarePaginator', function (): void {
    expect(GenericContainerReturnType::matches(returnTypeOf(fn(): LengthAwarePaginator => throw new LogicException())))
        ->toBeFalse();
});

it('does not match scalars, resources, Data classes, or the absent type', function (): void {
    expect(GenericContainerReturnType::matches(returnTypeOf(fn(): int => 0)))->toBeFalse()
        ->and(GenericContainerReturnType::matches(returnTypeOf(fn(): string => '')))->toBeFalse()
        ->and(GenericContainerReturnType::matches(returnTypeOf(fn(): JsonResource => throw new LogicException())))
        ->toBeFalse()
        ->and(GenericContainerReturnType::matches(returnTypeOf(fn(): ScalarOnlyData => throw new LogicException())))
        ->toBeFalse()
        ->and(GenericContainerReturnType::matches(returnTypeOf(fn() => null)))->toBeFalse();
});

it('does not match a union return type', function (): void {
    expect(GenericContainerReturnType::matches(returnTypeOf(fn(int|string $value): int|string => $value)))
        ->toBeFalse();
});
