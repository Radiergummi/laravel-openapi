<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

/**
 * How a route-model-bound path parameter resolves: the bound model, the key field the URL segment
 * matches against, and — when that field is the model's typed primary key — the JSON-Schema type
 * and format the key carries.
 *
 * The key/type decision is made entirely at resolution time: `type`/`format` are populated only
 * when the route binds by the model's own primary key, so a consumer can apply them unconditionally
 * without re-deriving which key is in play.
 *
 * @internal
 */
final readonly class RouteModelBinding
{
    /**
     * @param class-string $modelClass The bound `UrlRoutable` / Eloquent model class.
     * @param string       $key        The field the URL segment resolves against — a custom
     *                                 `{param:field}` segment, the model's `getRouteKeyName()`, or
     *                                 its primary key.
     * @param null|string  $type       The JSON-Schema type the key carries (`integer`/`string`),
     *                                 set only when the key is the model's typed primary key; null
     *                                 leaves the parameter's PHP type to stand.
     * @param null|string  $format     The JSON-Schema format (`uuid`) when known; null otherwise
     *                                 (including ULID keys, which have no standard format).
     */
    public function __construct(
        public string $modelClass,
        public string $key,
        public ?string $type,
        public ?string $format,
    ) {}
}
