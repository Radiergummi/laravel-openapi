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
 * Documents an HTTP request header that the operation consumes.
 *
 * Repeatable on classes and methods — class-level headers apply to every
 * operation on the controller; method-level entries add to them. Duplicate
 * names dedupe with method-level winning.
 *
 * ```php
 * #[OpenApi\Header('Idempotency-Key', description: 'Client-supplied idempotency token')]
 * #[OpenApi\Header('X-Tenant-Id', required: true, example: 'acme-corp')]
 * ```
 *
 * For response headers (rate-limit, ETag, Location) use a future
 * `ResponseHeader` attribute — those are scoped per response.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Header
{
    public function __construct(
        public string $name,
        public ?string $description = null,
        public bool $required = false,
        public string $type = 'string',
        public mixed $example = null,
    ) {}
}
