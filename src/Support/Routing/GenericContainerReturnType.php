<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

use ReflectionNamedType;
use ReflectionType;

use function is_a;

/**
 * Predicate identifying a *generic container* return type: one that names a collection of items
 * without carrying a resolvable item type — builtin `array`, or an `Illuminate\Support\Enumerable`
 * (`Support\Collection`, Eloquent `Collection`, `LazyCollection`).
 *
 * For such a return type the body's resource/data factory is the only type evidence, so callers
 * consult their return-expression body scan. Paginators are excluded by construction: they
 * implement `Illuminate\Contracts\Pagination\*`, not `Enumerable`, and are already claimed by the
 * paginator response resolver.
 *
 * @internal
 */
final class GenericContainerReturnType
{
    private const string ENUMERABLE = 'Illuminate\\Support\\Enumerable';

    public static function matches(?ReflectionType $type): bool
    {
        if (!$type instanceof ReflectionNamedType) {
            return false;
        }

        if ($type->isBuiltin()) {
            return $type->getName() === 'array';
        }

        return is_a($type->getName(), self::ENUMERABLE, allow_string: true);
    }
}
