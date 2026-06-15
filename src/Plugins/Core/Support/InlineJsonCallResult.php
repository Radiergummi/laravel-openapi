<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use OpenApi\Annotations as OA;

/**
 * The statically-read facts about one matched `response()->json(...)` call, independent of any
 * 2xx/non-2xx policy — the primary-slot resolver and the error contributor apply their opposite
 * status filters to the same facts (see {@see InlineJsonCallReader}).
 *
 * Status and body readability are reported separately because the two callers treat a non-literal
 * body differently: the primary resolver refuses the call (the body must be documentable to claim
 * the success slot), while the error contributor still emits a status-only error response (the
 * 4xx/5xx status alone is worth documenting). A non-literal *status* degrades for both — a body
 * must never be documented under a guessed status.
 *
 * @internal
 */
final readonly class InlineJsonCallResult
{
    /**
     * @param ?int       $status              the literal status (the `json()` status argument,
     *                                        200 when absent, or a chained `->setStatusCode()`
     *                                        override); null when present but not statically
     *                                        readable, or when a body-mutating chain degraded it
     * @param ?OA\Schema $bodySchema          the literal body as a schema, or null when there is
     *                                        no documentable body (empty `[]`, absent data
     *                                        argument, or a non-literal body)
     * @param bool       $bodyReadable        false when a body argument was present but not a
     *                                        compile-time literal (the status-only case)
     * @param ?string    $statusDegradeReason a human phrase for the caller's generation-log
     *                                        note when the status could not be read
     * @param ?string    $bodyDegradeReason   a human phrase for the caller's generation-log note
     *                                        when the body could not be read
     */
    public function __construct(
        public ?int $status,
        public ?OA\Schema $bodySchema = null,
        public bool $bodyReadable = true,
        public ?string $statusDegradeReason = null,
        public ?string $bodyDegradeReason = null,
    ) {}
}
