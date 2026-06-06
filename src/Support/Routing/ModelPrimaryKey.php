<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

/**
 * Tier-0 metadata about an Eloquent model's primary key, read by reflection from a bound model so
 * a route-model-bound path parameter can be typed from the key it resolves against.
 *
 * @internal
 */
final readonly class ModelPrimaryKey
{
    /**
     * @param string      $name   The primary-key column (`getKeyName()`, e.g. `id`). Used to decide
     *                            whether the key type applies to a given route key.
     * @param string      $type   The JSON-Schema type derived from `getKeyType()` — `integer` or `string`.
     * @param null|string $format The JSON-Schema format, when known (`uuid` for `HasUuids` models);
     *                            null otherwise (including ULID keys, which have no standard format).
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?string $format,
    ) {}
}
