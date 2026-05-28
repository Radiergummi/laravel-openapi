<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Enums\MediaType;

/**
 * Documents an additional response (typically an error status) that the generator cannot derive
 * automatically. Repeatable; the auto-derived 200/204 still emits as the primary response.
 *
 * `ref` resolves to a `#/components/schemas/…` ref via a registered {@see RefSchemaResolver}
 * (ApiResource, Data, …). `schema` is a literal JSON Schema and wins over `ref` when both are set.
 *
 * ```php
 * #[OpenApi\Response(status: 404, description: 'Project not found')]
 * #[OpenApi\Response(status: 422, description: 'Validation failed', ref: ValidationErrorResource::class)]
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Response
{
    /**
     * @param HttpStatusCode            $status
     * @param non-empty-string          $description
     * @param null|class-string         $ref
     * @param null|array<string, mixed> $schema
     */
    public function __construct(
        public int $status,
        public string $description,
        public ?string $ref = null,
        public ?array $schema = null,
        public ?MediaType $mediaType = null,
    ) {}
}
