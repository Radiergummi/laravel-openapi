<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Registry;

use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Errors\ErrorResponse;

/**
 * Resolves the body of a 4xx/5xx error response derived from `@throws` annotations and
 * auth/scope/throttle middleware.
 *
 * Implementations are consulted in registration order; the first non-null result wins.
 * Return {@see ErrorResponse::bodyless()} to claim the response while emitting no body.
 * The response key and default description are owned by {@see ErrorResponseInferenceStage}.
 *
 * Implementation notes:
 * - Catch exceptions internally and return null on failure.
 * - Branch on `$descriptor->exceptionClass` with `is_a($cls, X::class, true)`, not `===`.
 * - `$descriptor->action` is nullable; treat null as "no per-route constraints".
 * - Register component schemas idempotently (guard with `$registry->hasKey()`): this method
 *   is called once per status code per operation.
 */
interface ErrorResponseResolver
{
    public function resolveErrorResponse(ErrorDescriptor $descriptor): ?ErrorResponse;
}
