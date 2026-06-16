<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

/**
 * Resolution result for a route-model-bound path parameter. `type`/`format` are set only when
 * the route binds by the model's own primary key, so consumers can apply them unconditionally.
 *
 * @internal
 */
final readonly class RouteModelBinding
{
    /**
     * @param class-string $modelClass The bound Eloquent model class.
     * @param string       $key        The field the URL segment resolves against.
     * @param null|string  $type       JSON-Schema type (`integer`/`string`), set only for the model's typed primary key.
     * @param null|string  $format     JSON-Schema format (`uuid`) when known; null for ULID or unknown.
     */
    public function __construct(
        public string $modelClass,
        public string $key,
        public ?string $type,
        public ?string $format,
    ) {}
}
