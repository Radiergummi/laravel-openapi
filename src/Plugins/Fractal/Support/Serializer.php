<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Support;

/**
 * The Fractal serializer an endpoint uses at runtime. Determines the envelope
 * shape that {@see FractalEnvelopeFactory} builds for the documented response.
 *
 * Models the three named serializers shipped by `league/fractal`. For custom
 * serializers or `ArraySerializer` subclasses, use `#[Response]` to declare the
 * response schema explicitly.
 */
enum Serializer
{
    /**
     * Fractal's default: wraps output in `data`, with `meta.pagination` when paginated.
     */
    case DataArray;

    /**
     * `ArraySerializer`: no envelope for single items; top-level array for collections.
     * Paginated collections still wrap in `data` (Fractal's adapter forces it).
     */
    case ArraySerializer;

    /**
     * `JsonApiSerializer`: `{data: {type, id, attributes}}` for items, array for collections,
     * `meta.pagination` with hyphenated keys when paginated. The transformer schema maps to
     * `attributes`; per-resource `type`/`id` splitting requires a `#[Response]` override.
     */
    case JsonApi;
}
