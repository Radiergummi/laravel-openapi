<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Core\Pagination;

use Radiergummi\OpenApi\Enums\PaginatorKind;
use Radiergummi\OpenApi\Plugins\Core\Support\PaginatorCallReader;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Author;
use ReflectionMethod;

uses()->group('openapi');

/**
 * Parse-only fixture; actions are never invoked. The bodies exercise the call-shape whitelist of
 * {@see PaginatorCallReader} — only their source matters.
 */
class PaginatorCallReaderFixtureController
{
    public function lengthAware(): mixed
    {
        return Author::query()->paginate(15);
    }

    public function simple(): mixed
    {
        return Author::query()->simplePaginate(15);
    }

    public function cursor(): mixed
    {
        return Author::query()->cursorPaginate(15);
    }

    public function chained(): mixed
    {
        return Author::query()->paginate(25)->withQueryString();
    }

    public function staticCall(): mixed
    {
        return Author::paginate();
    }

    public function noPaginate(): mixed
    {
        return Author::query()->get();
    }

    public function conditional(bool $flag): mixed
    {
        if ($flag) {
            return Author::query()->paginate();
        }

        return Author::query()->get();
    }
}

function readPaginatorCall(string $method): ?PaginatorKind
{
    $reader = new PaginatorCallReader(new MethodBodyScanner());

    return $reader->detect(
        new ReflectionMethod(PaginatorCallReaderFixtureController::class, $method),
    );
}

it('detects paginate() as length-aware', function (): void {
    expect(readPaginatorCall('lengthAware'))->toBe(PaginatorKind::LengthAware);
});

it('detects simplePaginate() as simple', function (): void {
    expect(readPaginatorCall('simple'))->toBe(PaginatorKind::Simple);
});

it('detects cursorPaginate() as cursor', function (): void {
    expect(readPaginatorCall('cursor'))->toBe(PaginatorKind::Cursor);
});

it('detects a chained paginate()->withQueryString() call', function (): void {
    expect(readPaginatorCall('chained'))->toBe(PaginatorKind::LengthAware);
});

it('detects a static paginate() call', function (): void {
    expect(readPaginatorCall('staticCall'))->toBe(PaginatorKind::LengthAware);
});

it('returns null when no paginate call is present', function (): void {
    expect(readPaginatorCall('noPaginate'))->toBeNull();
});

it('ignores a paginate call guarded by a conditional', function (): void {
    expect(readPaginatorCall('conditional'))->toBeNull();
});
