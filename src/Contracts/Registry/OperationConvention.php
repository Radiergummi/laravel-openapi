<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Registry;

/**
 * The conventional operation defaults an {@see OperationConventionResolver} derives from a route's
 * Tier-0 signals — the success status code and a human summary. Either field may be null when the
 * resolver can derive one but not the other (e.g. a status code with no derivable resource noun).
 *
 * @internal Part of the Core-internal {@see OperationConventionResolver} seam; not a committed
 * public contract. See that interface for the rationale.
 */
final readonly class OperationConvention
{
    public function __construct(
        public ?int $successStatusCode = null,
        public ?string $summary = null,
    ) {}
}
