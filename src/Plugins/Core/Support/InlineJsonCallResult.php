<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use OpenApi\Annotations as OA;

/**
 * Statically-read facts about one `response()->json(...)` call, independent of 2xx/non-2xx policy.
 * Status and body readability are separate because callers treat a non-literal body differently:
 * the primary resolver refuses it, while the error contributor still emits a status-only response.
 * A non-literal status degrades both: a body must never be documented under a guessed status.
 *
 * @internal
 */
final readonly class InlineJsonCallResult
{
    /**
     * @param ?int       $status              literal status (200 when absent, or from a chained
     *                                        `->setStatusCode()`); null when not statically readable
     * @param ?OA\Schema $bodySchema          literal body schema, or null when absent/non-literal
     * @param bool       $bodyReadable        false when a body argument was present but not a literal
     * @param ?string    $statusDegradeReason human phrase for the generation-log note on status failure
     * @param ?string    $bodyDegradeReason   human phrase for the generation-log note on body failure
     */
    public function __construct(
        public ?int $status,
        public ?OA\Schema $bodySchema = null,
        public bool $bodyReadable = true,
        public ?string $statusDegradeReason = null,
        public ?string $bodyDegradeReason = null,
    ) {}
}
