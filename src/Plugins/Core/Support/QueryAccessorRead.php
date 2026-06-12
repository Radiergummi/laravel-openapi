<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

/**
 * One request-accessor read discovered in a controller method body — a single
 * `$request->query('sort')` / `->integer('page')` style call.
 *
 * @internal
 */
final readonly class QueryAccessorRead
{
    /**
     * @param string $name     the documented parameter name, already in wire notation
     *                         (`filter.name` → `filter[name]`)
     * @param string $accessor the matched accessor method (`query`, `input`, `string`,
     *                         `integer`, `boolean`)
     * @param string $type     the OpenAPI type inferred from the accessor
     * @param bool   $typed    whether the accessor itself names a type (`string()` /
     *                         `integer()` / `boolean()`), as opposed to the untyped
     *                         `query()` / `input()` bag accessors
     * @param mixed  $default  the accessor's literal default value when it matches the
     *                         inferred type; null when absent
     */
    public function __construct(
        public string $name,
        public string $accessor,
        public string $type,
        public bool $typed,
        public mixed $default = null,
    ) {}
}
