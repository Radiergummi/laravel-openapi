<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Registry;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Extractors\StandardResponsesExtractor;

/**
 * Produces the response body for standard error responses — the 4xx/5xx responses derived from
 * `@throws` annotations and auth/scope/throttle middleware.
 *
 * The core {@see StandardResponsesExtractor} decides *which* status codes an operation exposes and
 * their descriptions; the error *body shape* is delegated here so the error envelope stays a plugin
 * concern rather than being baked into the framework-agnostic core.
 *
 * Implementations are consulted in registration order; the first non-null result wins. When no
 * factory yields content, the extractor emits description-only error responses with no body.
 */
interface ErrorResponseFactory
{
    /**
     * Build the media-type content for a standard error response, or return null to defer to the
     * next registered factory.
     *
     * Implementations must idempotently register any shared component schema they reference.
     *
     * @return null|list<OA\MediaType>
     */
    public function errorResponseContent(): ?array;
}
