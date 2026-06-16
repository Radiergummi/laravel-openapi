<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Inclusion;

/**
 * One step in an inclusion-decision trace, emitted by {@see InclusionEvaluator} and rendered
 * by `openapi:why` / `openapi:generate --explain`. Pure data.
 *
 * @internal
 */
final readonly class TraceEntry
{
    public function __construct(
        public string $stage,
        public string $name,
        public bool $passed,
        public string $reason,
    ) {}
}
