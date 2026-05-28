<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Envelopes;

use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Errors\ErrorResponse;

/**
 * The explicit "no body" preset — claims every standard error response without emitting
 * content. Selected via `config('openapi.error_envelope') = 'none'` (the package default,
 * preserving today's bodyless behavior).
 */
final readonly class NoneEnvelope implements ErrorResponseResolver
{
    public function resolveErrorResponse(ErrorDescriptor $descriptor): ErrorResponse
    {
        return ErrorResponse::bodyless();
    }
}
