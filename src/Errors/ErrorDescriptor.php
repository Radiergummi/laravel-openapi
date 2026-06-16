<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Errors;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Throwable;

/**
 * Immutable snapshot of an inferred error response, passed to the envelope resolver chain.
 *
 * `$exceptionClass` is null for errors without a `@throws` source (auth, throttle, etc.).
 * Use `is_a($cls, X::class, true)` when branching on it; user code routinely subclasses.
 * `$action` is null when there is no route context; treat as "no per-route constraints apply".
 */
final readonly class ErrorDescriptor
{
    /**
     * `$shareableDescription` gates hoisting into the per-status shared component; route-specific
     * descriptions (e.g., `abort(403, 'Admins only')`) must not be shared (first-write-wins).
     * When `$bodySchema` is set the envelope resolver is skipped; literal bodies are never hoisted.
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
