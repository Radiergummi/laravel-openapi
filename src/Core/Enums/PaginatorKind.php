<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Enums;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;

use function is_a;

/**
 * The three Laravel paginator shapes, distinguished by the metadata each
 * serializes via its `toArray()` method.
 *
 * Spatie's `PaginatedDataCollection` and `CursorPaginatedDataCollection`
 * (matched by FQCN string to keep Core free of plugin imports) are recognised
 * as `LengthAware` and `Cursor` respectively: they delegate `toArray()` to the
 * underlying Laravel paginator with `Data`-transformed items, so the envelope
 * shape is identical. This lets `PaginatorResponseResolver` model both
 * Laravel-native and Spatie-collection return types from a single code path.
 */
enum PaginatorKind
{
    private const string SPATIE_PAGINATED_DATA_COLLECTION = 'Spatie\\LaravelData\\PaginatedDataCollection';

    private const string SPATIE_CURSOR_PAGINATED_DATA_COLLECTION = 'Spatie\\LaravelData\\CursorPaginatedDataCollection';

    case LengthAware;

    case Simple;

    case Cursor;

    /**
     * Maps a class name to its paginator kind, or null when the class is not a
     * paginator. Order matters: `LengthAwarePaginator` extends `Paginator`, so
     * the more specific contract must be tested first.
     */
    public static function fromClass(string $class): ?self
    {
        return match (true) {
            is_a($class, CursorPaginator::class, allow_string: true) => self::Cursor,
            is_a($class, LengthAwarePaginator::class, allow_string: true) => self::LengthAware,
            is_a($class, Paginator::class, allow_string: true) => self::Simple,
            is_a($class, self::SPATIE_CURSOR_PAGINATED_DATA_COLLECTION, allow_string: true) => self::Cursor,
            is_a($class, self::SPATIE_PAGINATED_DATA_COLLECTION, allow_string: true) => self::LengthAware,
            default => null,
        };
    }
}
