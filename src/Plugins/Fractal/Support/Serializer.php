<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Support;

/**
 * The Fractal serializer an endpoint uses at runtime. Determines the envelope
 * shape that {@see FractalEnvelopeFactory} builds for the documented response.
 *
 * Three values are modelled, one for each named serializer shipped by
 * `league/fractal`. Custom serializers and Fractalistic's `ArraySerializer`
 * subclasses fall outside this enum; use a `#[Response]` attribute on the
 * action to declare the response schema explicitly for those cases.
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
