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
 * Declares the HTTP response a throwable produces, replacing the config-map fallback used for
 * framework exceptions you don't own.
 *
 * ```php
 * #[ExceptionResponse(status: 409, description: 'The resource already exists')]
 * class DuplicateResourceException extends RuntimeException {}
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ExceptionResponse
{
    public function __construct(
        public int $status,
        public string $description,
    ) {}
}
