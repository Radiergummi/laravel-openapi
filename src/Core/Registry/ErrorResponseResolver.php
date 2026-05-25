<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Registry;

use Radiergummi\OpenApi\Core\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Core\Errors\ErrorResponse;
use Radiergummi\OpenApi\Core\Extractors\StandardResponsesExtractor;

/**
 * Resolves the body of a standard error response — the 4xx/5xx responses derived from
 * `@throws` annotations and auth/scope/throttle middleware.
 *
 * Implementations are consulted in registration order; the first non-null result wins.
 * Return {@see ErrorResponse::bodyless()} to claim the response while emitting no body.
 *
 * The response key (`response`), named-component registration (`Unauthorized`, `Forbidden`,
 * ...), and default description are owned by {@see StandardResponsesExtractor}. The returned
 * {@see ErrorResponse} carries only the body slice — content, headers, links, and an
 * optional description override — that's why the type intentionally lacks a response-key
 * field.
 *
 * Implementations must catch exceptions internally and return null on failure, so a
 * misbehaving resolver does not abort a full generation run (matching the
 * {@see PrimaryResponseResolver} contract).
 *
 * Branching on `$descriptor->exceptionClass` must use `is_a($cls, X::class, true)`, not
 * strict equality — user code routinely subclasses framework exceptions.
 *
 * Implementations that register shared component schemas via `ComponentSchemaRegistry` must
 * do so idempotently — typically by guarding with `$registry->hasKey()`. This method is
 * invoked once per status code per operation, so non-idempotent registration silently bakes
 * the first call's context into the shared component for every subsequent call.
 */
interface ErrorResponseResolver
{
    public function resolveErrorResponse(ErrorDescriptor $descriptor): ?ErrorResponse;
}
