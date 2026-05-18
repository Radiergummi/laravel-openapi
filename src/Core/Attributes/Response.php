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
 * Documents an additional response for an operation — typically an error
 * status (404, 422, 429, …) that the generator cannot derive automatically.
 *
 * The auto-derived 200/204 response is emitted regardless and remains the
 * primary response. Multiple attributes may be stacked to describe each
 * additional status.
 *
 * `$ref` accepts either an {@see ApiResource} subclass (for JSON:API-shaped
 * error envelopes) or a {@see Data} subclass (for plain JSON error bodies).
 * The referenced schema is registered as a component automatically.
 *
 * `$schema` accepts a literal JSON Schema array and is emitted as an inline
 * schema. When both `$ref` and `$schema` are provided, `$schema` wins.
 *
 * `$mediaType` overrides the content type for the response body. Defaults to
 * `application/json`. Use `text/event-stream` to document SSE event payloads
 * alongside the auto-detected streaming response:
 *
 * ```php
 * #[OpenApi\Operation(streaming: true)]
 * #[OpenApi\Response(status: 200, description: 'OK', mediaType: MediaType::EventStream, schema: ['type' => 'object', 'properties' => ['type' => ['type' => 'string']]])]
 * ```
 *
 * ```php
 * #[OpenApi\Response(status: 404, description: 'Project not found')]
 * #[OpenApi\Response(status: 422, description: 'Validation failed', ref: ValidationErrorResource::class)]
 * #[OpenApi\Response(status: 200, description: 'OK', schema: ['type' => 'object', 'properties' => ['uuid' => ['type' => 'string']]])]
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Response
{
    /**
     * @param null|class-string         $ref       Class that a registered {@see \Radiergummi\OpenApi\Core\Registry\RefSchemaResolver}
     *                                             can resolve to a `#/components/schemas/…` ref (e.g. an
     *                                             ApiResource or Data subclass). Resolution is plugin-driven.
     * @param null|array<string, mixed> $schema    Literal JSON Schema array. Takes
     *                                             precedence over `$ref` when both are set.
     * @param null|MediaType            $mediaType Content-type for the response body.
     *                                             Defaults to `application/json` when a
     *                                             schema or ref is provided.
     */
    public function __construct(
        public int $status,
        public string $description,
        public ?string $ref = null,
        public ?array $schema = null,
        public ?MediaType $mediaType = null,
    ) {}
}
