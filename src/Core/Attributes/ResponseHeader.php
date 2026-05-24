<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;

/**
 * Documents an HTTP response header that the operation emits.
 *
 * Repeatable on classes, methods, and functions. The `status` argument scopes the header to a
 * particular response (e.g. `Location` on a `201 Created`); if no matching response exists for the
 * given status, the header is dropped silently.
 *
 * Declaring on the controller class applies the header to every action on that controller — useful
 * for headers like `X-Request-Id` or `X-RateLimit-Remaining` that every operation emits.
 * Method-level declarations win on `(status, name)` collision; declaration order is otherwise
 * preserved.
 *
 * ```php
 * #[OpenApi\Response(status: 201, description: 'Created')]
 * #[OpenApi\ResponseHeader(
 *     name: 'Location',
 *     status: 201,
 *     type: 'string',
 *     format: 'uri',
 *     description: 'URL of the created resource',
 * )]
 * ```
 *
 * For request headers, use {@see Header}.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class ResponseHeader
{
    public function __construct(
        public string $name,
        public int $status = 200,
        public ?string $description = null,
        public string $type = 'string',
        public ?string $format = null,
        public mixed $example = null,
        public ?bool $required = null,
        public ?bool $deprecated = null,
    ) {}
}
