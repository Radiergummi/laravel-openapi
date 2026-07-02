<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

/**
 * One request-accessor read discovered in a controller method body: a single
 * `$request->query('sort')` / `->integer('page')` / `->cookie('session')` / `->header('X-Api-Key')`
 * style call.
 *
 * @internal
 */
final readonly class AccessorRead
{
    /**
     * @param string $name     documented parameter name; query reads use wire notation
     *                         (`filter.name` → `filter[name]`), cookie/header reads keep the raw
     *                         literal token (`X-Api-Key`)
     * @param string $accessor matched accessor method (`query`, `input`, `string`, `integer`,
     *                         `boolean`, `cookie`, `header`)
     * @param string $location parameter location the accessor maps to (`query`, `cookie`, `header`)
     * @param string $type     OpenAPI type inferred from the accessor
     * @param bool   $typed    true when the accessor names a scalar type (`string()`, `integer()`,
     *                         `boolean()`), false for untyped bag accessors and the string-valued
     *                         cookie/header locations
     * @param mixed  $default  accessor's literal default value when present; null otherwise
     */
    public function __construct(
        public string $name,
        public string $accessor,
        public string $location,
        public string $type,
        public bool $typed,
        public mixed $default = null,
    ) {}
}
