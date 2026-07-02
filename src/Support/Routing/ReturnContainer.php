<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

/**
 * The structural container an action's return type describes: a single value, a plain list of
 * values, or a paginated envelope. The paginator sub-kind (cursor / length-aware / simple) is a
 * property of the {@see Paginated} envelope, not a distinct container, so it rides separately on
 * {@see ReturnShape::$paginatorKind}.
 *
 * @internal
 */
enum ReturnContainer
{
    /** A single value: an object, scalar, map (`array<string, T>`), array shape, or union. */
    case Single;

    /** A JSON array whose element type is isolated on {@see ReturnShape::$itemType}. */
    case ListOf;

    /** A paginator envelope; the concrete shape is on {@see ReturnShape::$paginatorKind}. */
    case Paginated;
}
