<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Envelopes;

use Override;
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
    #[Override]
    public function resolveErrorResponse(ErrorDescriptor $descriptor): ErrorResponse
    {
        return ErrorResponse::bodyless();
    }
}
