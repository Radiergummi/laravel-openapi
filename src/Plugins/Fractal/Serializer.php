<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal;

/**
 * The Fractal serializer an endpoint uses at runtime. Determines the envelope
 * shape that {@see FractalEnvelopeFactory} builds for the documented response.
 *
 * Three values are modelled — the three named serializers shipped by
 * `league/fractal`. Custom serializers and Fractalistic's `ArraySerializer`
 * subclasses fall outside this enum; use a `#[Response]` attribute on the
 * action to declare the response schema explicitly for those cases.
 */
enum Serializer
{
    /**
     * Fractal's default. Wraps every output in a `data` key:
     * `{data}` for a single item, `{data: [...]}` for a collection,
     * `{data: [...], meta: {pagination: …}}` when paginated.
     */
    case DataArray;

    /**
     * `League\Fractal\Serializer\ArraySerializer`. Produces no envelope for
     * single items (the item is the response) and a top-level array for
     * collections. Paginated collections still wrap in `data` because
     * Fractal's `IlluminatePaginatorAdapter` wraps regardless.
     */
    case ArraySerializer;

    /**
     * `League\Fractal\Serializer\JsonApiSerializer`. Produces JSON:API
     * resource objects: `{data: {type, id, attributes}}` for a single item,
     * an array of resource objects for a collection, and a `meta.pagination`
     * block with hyphenated keys when paginated. The transformer's full
     * schema becomes the `attributes` body; modelling per-resource `type` and
     * splitting `id` out of `attributes` is left to a `#[Response]` override.
     */
    case JsonApi;
}
