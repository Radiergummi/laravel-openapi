<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Routing;

use Closure;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Radiergummi\OpenApi\Enums\PaginatorKind;
use Radiergummi\OpenApi\Support\Routing\ReturnContainer;
use Radiergummi\OpenApi\Support\Routing\ReturnShape;
use Radiergummi\OpenApi\Support\Routing\ReturnShapeResolver;
use Radiergummi\OpenApi\Tests\Fixtures\ScalarOnlyData;
use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use Symfony\Component\TypeInfo\Type\ArrayShapeType;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\Type\UnionType;

/**
 * Fixture: each method's signature and `@return` tag exercises one branch of the resolver. The
 * `use` imports above give the docblock context a way to resolve the short names.
 */
class ReturnShapeFixture
{
    public function singleObject(): ScalarOnlyData
    {
        return new ScalarOnlyData('x', 1);
    }

    public function nullableObject(): ?ScalarOnlyData
    {
        return null;
    }

    public function union(): ScalarOnlyData|stdClass
    {
        return new stdClass();
    }

    public function nullableUnion(): ScalarOnlyData|stdClass|null
    {
        return null;
    }

    /** @return LengthAwarePaginator<stdClass> */
    public function lengthAwarePaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, 15);
    }

    /** @return CursorPaginator<stdClass> */
    public function cursorPaginator(): CursorPaginator
    {
        return new CursorPaginator([], 15);
    }

    /** @return Paginator<stdClass> */
    public function simplePaginator(): Paginator
    {
        return new Paginator([], 15);
    }

    public function paginatorWithoutGeneric(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, 15);
    }

    /** @return array<stdClass> */
    public function arrayOfObjects(): array
    {
        return [];
    }

    /** @return stdClass[] */
    public function arrayShorthand(): array
    {
        return [];
    }

    /** @return Collection<int, stdClass> */
    public function collection(): Collection
    {
        return new Collection();
    }

    /** @return array<string, stdClass> */
    public function stringKeyedMap(): array
    {
        return [];
    }

    /** @return array{id: int, name: string} */
    public function arrayShape(): array
    {
        return ['id' => 1, 'name' => 'x'];
    }

    /** @return list<array{id: int}> */
    public function listOfShapes(): array
    {
        return [];
    }

    public function bareArray(): array
    {
        return [];
    }

    public function untyped() // @phpstan-ignore missingType.return (fixture is intentionally untyped)
    {
        return null;
    }

    public function mixedReturn(): mixed
    {
        return null;
    }

    /**
     * @return \Totally\Bogus\Klass
     *
     * @phpstan-ignore class.notFound (fixture names a deliberately unresolvable class)
     */
    public function unresolvable(): object
    {
        throw new RuntimeException('Signature-only fixture; never invoked.');
    }
}

function describeReturn(string $method): ReturnShape
{
    return ReturnShapeResolver::create()->describe(
        new ReflectionMethod(ReturnShapeFixture::class, $method),
    );
}

/** The item type's class name when it is an object type, for PHPStan-narrowed assertions. */
function itemObjectClass(ReturnShape $shape): ?string
{
    return $shape->itemType instanceof ObjectType ? $shape->itemType->getClassName() : null;
}

it('describes a plain typed object as a non-nullable single value', function (): void {
    $shape = describeReturn('singleObject');

    expect($shape->container)->toBe(ReturnContainer::Single)
        ->and($shape->nullable)->toBeFalse()
        ->and($shape->unionMembers)->toBe([])
        ->and($shape->paginatorKind)->toBeNull()
        ->and($shape->itemType)->toBeInstanceOf(ObjectType::class)
        ->and(itemObjectClass($shape))->toBe(ScalarOnlyData::class);
});

it('marks a nullable object as nullable and unwraps the item type', function (): void {
    $shape = describeReturn('nullableObject');

    expect($shape->container)->toBe(ReturnContainer::Single)
        ->and($shape->nullable)->toBeTrue()
        ->and($shape->itemType)->toBeInstanceOf(ObjectType::class)
        ->and(itemObjectClass($shape))->toBe(ScalarOnlyData::class);
});

it('collects the members of a multi-class union', function (): void {
    $shape = describeReturn('union');

    expect($shape->container)->toBe(ReturnContainer::Single)
        ->and($shape->nullable)->toBeFalse()
        ->and($shape->unionMembers)->toHaveCount(2)
        ->and($shape->itemType)->toBeInstanceOf(UnionType::class);
});

it('honours the null member of a nullable union', function (): void {
    $shape = describeReturn('nullableUnion');

    expect($shape->container)->toBe(ReturnContainer::Single)
        ->and($shape->nullable)->toBeTrue()
        ->and($shape->unionMembers)->toHaveCount(2);
});

it('describes a length-aware paginator with its item type', function (): void {
    $shape = describeReturn('lengthAwarePaginator');

    expect($shape->container)->toBe(ReturnContainer::Paginated)
        ->and($shape->paginatorKind)->toBe(PaginatorKind::LengthAware)
        ->and($shape->itemType)->toBeInstanceOf(ObjectType::class)
        ->and(itemObjectClass($shape))->toBe(stdClass::class);
});

it('describes a cursor paginator', function (): void {
    $shape = describeReturn('cursorPaginator');

    expect($shape->container)->toBe(ReturnContainer::Paginated)
        ->and($shape->paginatorKind)->toBe(PaginatorKind::Cursor);
});

it('describes a simple paginator', function (): void {
    $shape = describeReturn('simplePaginator');

    expect($shape->container)->toBe(ReturnContainer::Paginated)
        ->and($shape->paginatorKind)->toBe(PaginatorKind::Simple);
});

it('describes a paginator without a generic as paginated with a null item type', function (): void {
    $shape = describeReturn('paginatorWithoutGeneric');

    expect($shape->container)->toBe(ReturnContainer::Paginated)
        ->and($shape->paginatorKind)->toBe(PaginatorKind::LengthAware)
        ->and($shape->itemType)->toBeNull();
});

it('describes array<T> as a list of the value type', function (): void {
    $shape = describeReturn('arrayOfObjects');

    expect($shape->container)->toBe(ReturnContainer::ListOf)
        ->and($shape->itemType)->toBeInstanceOf(ObjectType::class)
        ->and(itemObjectClass($shape))->toBe(stdClass::class);
});

it('describes T[] as a list of the value type', function (): void {
    $shape = describeReturn('arrayShorthand');

    expect($shape->container)->toBe(ReturnContainer::ListOf)
        ->and($shape->itemType)->toBeInstanceOf(ObjectType::class)
        ->and(itemObjectClass($shape))->toBe(stdClass::class);
});

it('describes a typed Collection as a single value carrying the collection type', function (): void {
    // The schema engine renders a CollectionType as an array/map directly, so the descriptor keeps
    // the whole type on Single rather than duplicating its key-semantics to isolate an element.
    $shape = describeReturn('collection');

    expect($shape->container)->toBe(ReturnContainer::Single)
        ->and($shape->itemType)->toBeInstanceOf(CollectionType::class);
});

it('describes a string-keyed map as a single value carrying the collection type', function (): void {
    // A string key makes `array<string, T>` a map, not a list: it stays Single with the whole
    // CollectionType, which the schema engine renders as `additionalProperties`. This is the
    // map-vs-list split #479's typed-return resolver builds on (Single.itemType fed to fromType).
    $shape = describeReturn('stringKeyedMap');

    expect($shape->container)->toBe(ReturnContainer::Single)
        ->and($shape->itemType)->toBeInstanceOf(CollectionType::class);
});

it('describes an array shape as a single value carrying the shape type', function (): void {
    $shape = describeReturn('arrayShape');

    expect($shape->container)->toBe(ReturnContainer::Single)
        ->and($shape->itemType)->toBeInstanceOf(ArrayShapeType::class);
});

it('describes list<array{...}> as a list of the shape type', function (): void {
    $shape = describeReturn('listOfShapes');

    expect($shape->container)->toBe(ReturnContainer::ListOf)
        ->and($shape->itemType)->toBeInstanceOf(ArrayShapeType::class);
});

it('describes a bare array as a list of undeclared items', function (): void {
    $shape = describeReturn('bareArray');

    expect($shape->container)->toBe(ReturnContainer::ListOf)
        ->and($shape->itemType)->toBeNull();
});

it('describes an untyped return as a single value with no item type', function (): void {
    $shape = describeReturn('untyped');

    expect($shape->container)->toBe(ReturnContainer::Single)
        ->and($shape->itemType)->toBeNull()
        ->and($shape->nullable)->toBeFalse();
});

it('describes a mixed return as a single value', function (): void {
    $shape = describeReturn('mixedReturn');

    expect($shape->container)->toBe(ReturnContainer::Single);
});

it('degrades to a null item type when the return class cannot be resolved', function (): void {
    $shape = describeReturn('unresolvable');

    expect($shape->container)->toBe(ReturnContainer::Single)
        ->and($shape->itemType)->toBeNull();
});

it('describes a closure reflector', function (): void {
    $closure = function (): ScalarOnlyData {
        return new ScalarOnlyData('x', 1);
    };

    $shape = ReturnShapeResolver::create()->describe(new ReflectionFunction(Closure::fromCallable($closure)));

    expect($shape->container)->toBe(ReturnContainer::Single)
        ->and($shape->itemType)->toBeInstanceOf(ObjectType::class)
        ->and(itemObjectClass($shape))->toBe(ScalarOnlyData::class);
});
