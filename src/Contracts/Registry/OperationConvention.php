<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Registry;

/**
 * Conventional operation defaults (success status code and summary) derived from a route.
 * Either field may be null when only one can be derived.
 *
 * @internal Part of the {@see OperationConventionResolver} seam; not a committed public contract.
 */
final readonly class OperationConvention
{
    public function __construct(
        public ?int $successStatusCode = null,
        public ?string $summary = null,
    ) {}
}
