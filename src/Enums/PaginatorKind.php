<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Enums;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;

use function is_a;
use function strtolower;

/**
 * The three Laravel paginator shapes, distinguished by their `toArray()` envelope.
 *
 * Spatie's `PaginatedDataCollection` / `CursorPaginatedDataCollection` are matched by FQCN string
 * (to keep Core free of plugin imports) and map to `LengthAware` / `Cursor` respectively, since
 * they delegate `toArray()` to the underlying Laravel paginator.
 */
enum PaginatorKind
{
    private const string SPATIE_PAGINATED_DATA_COLLECTION = 'Spatie\\LaravelData\\PaginatedDataCollection';

    private const string SPATIE_CURSOR_PAGINATED_DATA_COLLECTION = 'Spatie\\LaravelData\\CursorPaginatedDataCollection';

    case LengthAware;

    case Simple;

    case Cursor;

    /**
     * `LengthAwarePaginator` extends `Paginator`, so the more specific contract is tested first.
     */
    public static function fromClass(string $class): ?self
    {
        return match (true) {
            is_a($class, CursorPaginator::class, allow_string: true),
            is_a($class, self::SPATIE_CURSOR_PAGINATED_DATA_COLLECTION, allow_string: true) => self::Cursor,
            is_a($class, LengthAwarePaginator::class, allow_string: true),
            is_a($class, self::SPATIE_PAGINATED_DATA_COLLECTION, allow_string: true) => self::LengthAware,
            is_a($class, Paginator::class, allow_string: true) => self::Simple,
            default => null,
        };
    }

    /** Sibling to {@see fromClass}; maps a builder method name to its kind. */
    public static function fromPaginatingMethod(string $method): ?self
    {
        return match (strtolower($method)) {
            'paginate' => self::LengthAware,
            'simplepaginate' => self::Simple,
            'cursorpaginate' => self::Cursor,
            default => null,
        };
    }
}
