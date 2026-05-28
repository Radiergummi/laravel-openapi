<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Errors;

use Throwable;

/**
 * A small immutable view of "what we've inferred about this error response, handed to the
 * resolver."
 *
 * Carries the exception class (the semantic origin) alongside the status code (needed for
 * problem details' literal `status` field, JSON:API's per-error `status`, and well-known
 * component-name lookup). `exceptionClass` is nullable because not every standard response
 * originates from a `@throws` — middleware-detected responses (auth/scope/throttle) carry
 * their canonical thrown exception via the extended middleware-responses config, but
 * third-party middleware mappings users add without an exception class still work.
 *
 * Resolvers branching on `$exceptionClass` must use `is_a($cls, X::class, true)`, not strict
 * equality — user code routinely subclasses framework exceptions.
 */
final readonly class ErrorDescriptor
{
    /**
     * @param null|class-string<Throwable> $exceptionClass
     */
    public function __construct(
        public int $status,
        public ?string $exceptionClass,
        public string $description,
    ) {}
}
