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
 * ```php
 * #[FractalResponse(transformer: BookTransformer::class)]                       // single
 * #[FractalResponse(transformer: BookTransformer::class, collection: true)]    // flat collection
 * #[FractalResponse(transformer: BookTransformer::class, paginated: true)]     // paginated collection
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
     */
    public function __construct(
        public string $transformer,
        public bool $collection = false,
        public bool $paginated = false,
    ) {}
}
