<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Errors;

use OpenApi\Annotations as OA;

/**
 * The body slice of an error response, produced by an {@see ErrorResponseResolver}.
 *
 * Carries only what a resolver can produce: media-type contents, response headers, links, and
 * an optional description that overrides the extractor's default. The response key, named-
 * component registration, and default description are owned by
 * {@see \Radiergummi\OpenApi\Core\Extractors\StandardResponsesExtractor} — there is
 * intentionally no slot for them on this type.
 */
final readonly class ErrorResponse
{
    /**
     * @param list<OA\MediaType> $content
     * @param list<OA\Header>    $headers
     * @param list<OA\Link>      $links
     */
    public function __construct(
        public array $content = [],
        public array $headers = [],
        public array $links = [],
        public ?string $description = null,
    ) {}

    /**
     * Claim the response with no body.
     */
    public static function bodyless(): self
    {
        return new self();
    }
}
