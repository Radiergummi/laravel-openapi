<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;

/**
 * Overrides any subset of the auto-derived operation metadata for a route.
 *
 * Any property left null falls back to its auto-derived value (docblock for
 * summary/description, the route name for operationId, the controller's
 * namespace tail for tags).
 *
 * **Precedence:** method-level overrides class-level.
 *
 * **Tags:** by default `tags:` is merged with the namespace-derived tag set.
 * Pass `replace: true` to discard the auto-derived tags and use only the
 * explicitly provided ones. For purely additive tagging, prefer {@see Tag}.
 *
 * **Streaming:** set `streaming: true` to advertise `text/event-stream` as the
 * response content type. This is automatically detected when the controller
 * method's return type is `StreamedResponse`, so the attribute is only needed
 * when the method hides the return type behind a union or `Response` base class.
 *
 * ```php
 * // Merge: operation gets both the namespace tag AND 'Search'
 * #[OpenApi\Operation(tags: ['Search'])]
 *
 * // Replace: operation gets only 'Search', namespace tag is dropped
 * #[OpenApi\Operation(tags: ['Search'], replace: true)]
 *
 * // Streaming SSE endpoint
 * #[OpenApi\Operation(streaming: true)]
 * public function stream(): Response { … }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Operation
{
    /**
     * @param null|list<string> $tags      Tags to add (merge) or replace the auto-derived set.
     *                                     Whether they merge or replace depends on {@see $replace}.
     * @param bool              $replace   When true, `$tags` replaces the namespace-derived set
     *                                     entirely. When false (default), they are merged.
     * @param bool              $streaming When true, the 200 response advertises
     *                                     `text/event-stream` instead of the default JSON response
     *                                     media type. Auto-detected when the return type is
     *                                     `StreamedResponse`.
     */
    public function __construct(
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $operationId = null,
        public ?array $tags = null,
        public bool $replace = false,
        public bool $streaming = false,
    ) {}
}
