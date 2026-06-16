<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

/**
 * One request-accessor read discovered in a controller method body: a single
 * `$request->query('sort')` / `->integer('page')` style call.
 *
 * @internal
 */
final readonly class QueryAccessorRead
{
    /**
     * @param string $name     documented parameter name in wire notation (`filter.name` → `filter[name]`)
     * @param string $accessor matched accessor method (`query`, `input`, `string`, `integer`, `boolean`)
     * @param string $type     OpenAPI type inferred from the accessor
     * @param bool   $typed    true when the accessor names a type (`string()`, `integer()`, `boolean()`),
     *                         false for untyped bag accessors (`query()`, `input()`)
     * @param mixed  $default  accessor's literal default value when present; null otherwise
     */
    public function __construct(
        public string $name,
        public string $accessor,
        public string $type,
        public bool $typed,
        public mixed $default = null,
    ) {}
}
