<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Errors;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
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
 * `$action` is the {@see ActionDescriptor} of the route that produced this error, when
 * available. Resolvers use it to scope their envelope per-route — e.g. a JSON:API plugin
 * that only wants to apply `application/vnd.api+json` error bodies on JSON:API routes, while
 * non-JSON:API routes keep the configured `error_envelope` default. The property is nullable
 * because future contributors may produce error descriptors without a route context (e.g.
 * webhook-only or top-level component-default errors); resolvers that branch on it must
 * handle the `null` case (typically by treating it as "no per-route constraints apply").
 *
 * Resolvers branching on `$exceptionClass` must use `is_a($cls, X::class, true)`, not strict
 * equality — user code routinely subclasses framework exceptions.
 */
final readonly class ErrorDescriptor
{
    /**
     * `$shareableDescription` declares whether the description may be hoisted into the shared
     * per-status response component (`components.responses.Forbidden` etc.). Config- and
     * attribute-mapped descriptions are canonical per status and share fine; a description
     * authored for one route (e.g. an `abort(403, 'Admins only')` message) must stay inlined on
     * that operation — the shared component is first-write-wins and would leak the text into
     * every other operation with the same status.
     *
     * `$bodySchema` carries a literal response body read from the controller (a non-2xx
     * `response()->json([...], <4xx/5xx>)` literal — see
     * {@see \Radiergummi\OpenApi\Plugins\Core\ErrorContributors\InlineJsonErrorContributor}). When
     * present, {@see \Radiergummi\OpenApi\Support\Generator\Stages\ErrorResponseInferenceStage}
     * composes the response from this schema (JSON media type, inlined on the operation) and skips
     * the {@see \Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver} envelope chain for
     * that status: a literal body the author wrote wins over the configured envelope. A literal
     * body is route-specific, so it is never hoisted into the shared `components.responses.*`
     * component (same reasoning as a route-authored `abort()` message).
     *
     * @param null|class-string<Throwable> $exceptionClass
     */
    public function __construct(
        public int $status,
        public ?string $exceptionClass,
        public string $description,
        public ?ActionDescriptor $action = null,
        public bool $shareableDescription = true,
        public ?OA\Schema $bodySchema = null,
    ) {}
}
