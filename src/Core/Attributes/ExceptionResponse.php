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
 * Declares the HTTP response that a throwable class produces.
 *
 * Place this on an exception class to register its OpenAPI response mapping
 * directly on the class itself, rather than in `config/openapi.php`. The
 * {@see \Radiergummi\OpenApi\Core\Extractors\StandardResponsesExtractor} checks for
 * this attribute first; the config map acts as a fallback for framework
 * exceptions you don't own.
 *
 * Example:
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
