<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Envelopes;

use Override;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Errors\ErrorResponse;

/**
 * Claims every standard error response without emitting a body. The package default.
 */
final readonly class NoneEnvelope implements ErrorResponseResolver
{
    #[Override]
    public function resolveErrorResponse(ErrorDescriptor $descriptor): ErrorResponse
    {
        return ErrorResponse::bodyless();
    }
}
