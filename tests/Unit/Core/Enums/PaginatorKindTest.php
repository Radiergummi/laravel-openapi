<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Core\Enums;

use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Radiergummi\OpenApi\Core\Enums\PaginatorKind;
use stdClass;

it('detects a length-aware paginator', function (): void {
    expect(PaginatorKind::fromClass(LengthAwarePaginator::class))
        ->toBe(PaginatorKind::LengthAware);
});

it('detects a simple paginator', function (): void {
    expect(PaginatorKind::fromClass(Paginator::class))
        ->toBe(PaginatorKind::Simple);
});

it('detects a cursor paginator', function (): void {
    expect(PaginatorKind::fromClass(CursorPaginator::class))
        ->toBe(PaginatorKind::Cursor);
});

it('returns null for a non-paginator class', function (): void {
    expect(PaginatorKind::fromClass(stdClass::class))->toBeNull();
});

it('returns null for a class that does not exist', function (): void {
    expect(PaginatorKind::fromClass('Not\\A\\Real\\Class'))->toBeNull();
});
