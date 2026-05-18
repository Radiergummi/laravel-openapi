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
use Radiergummi\OpenApi\Core\Enums\MediaType;

/**
 * Overrides metadata on the auto-derived request body or creates one for
 * endpoints that don't accept a {@see \Spatie\LaravelData\Data} class.
 *
 * Any property left null falls through to the auto-derived value: the body
 * stays attached to its `Data` schema (when present), the media type defaults
 * to `application/json`, and `required` defaults to true.
 *
 * Useful escape hatches:
 *
 * - Stripe/Slack webhook endpoints that accept opaque JSON.
 * - Multipart uploads where the underlying Data class only models the metadata.
 * - Marking an otherwise-required body as optional.
 *
 * ```php
 * #[OpenApi\RequestBody(description: 'Stripe event payload', mediaType: MediaType::Json)]
 * public function handleWebhook(Request $request) { … }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class RequestBody
{
    public function __construct(
        public ?string $description = null,
        public ?bool $required = null,
        public ?MediaType $mediaType = null,
    ) {}
}
