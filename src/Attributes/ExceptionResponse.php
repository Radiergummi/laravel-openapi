<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

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
    /**
     * @param HttpStatusCode   $status
     * @param non-empty-string $description
     */
    public function __construct(
        public int $status,
        public string $description,
    ) {}
}
