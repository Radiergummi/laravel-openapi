<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Attributes;

use Attribute;
use Radiergummi\OpenApi\Plugins\Fractal\Serializer;

/**
 * Binds an endpoint to the Fractal transformer that shapes its response.
 *
 * Method-level: a Fractal transformer is applied inside a method body, which
 * the generator never reads, so the binding is declared explicitly.
 *
 * `paginated: true` implies a paginated collection — the envelope gains
 * `meta.pagination` matching Fractal's `IlluminatePaginatorAdapter` shape, and
 * the resolver treats the response as a collection regardless of the
 * `collection` flag.
 *
 * `serializer:` names the Fractal serializer the endpoint uses at runtime.
 * Defaults to {@see Serializer::DataArray}, Fractal's default. Use
 * {@see Serializer::ArraySerializer} or {@see Serializer::JsonApi} when the
 * action calls `Manager::setSerializer(…)` to switch shape.
 *
 * ```php
 * #[FractalResponse(transformer: BookTransformer::class)]                       // single
 * #[FractalResponse(transformer: BookTransformer::class, collection: true)]    // flat collection
 * #[FractalResponse(transformer: BookTransformer::class, paginated: true)]     // paginated collection
 * #[FractalResponse(transformer: BookTransformer::class, serializer: Serializer::JsonApi)]
 * public function index(): JsonResponse { … }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class FractalResponse
{
    /**
     * @param class-string $transformer The transformer class shaping the response.
     * @param bool         $collection  True when the endpoint returns a (non-paginated) collection.
     * @param bool         $paginated   True for a paginated collection — implies `collection: true`.
     * @param Serializer   $serializer  The Fractal serializer the endpoint uses; defaults to `DataArray`.
     */
    public function __construct(
        public string $transformer,
        public bool $collection = false,
        public bool $paginated = false,
        public Serializer $serializer = Serializer::DataArray,
    ) {}
}
