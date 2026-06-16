<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Inclusion;

use Radiergummi\OpenApi\Events\SkipReason;

/**
 * Result of {@see InclusionEvaluator::decide()} for one (route × spec) pair. `included` is
 * the final verdict. `trace` lists the checks; `summary` is a one-line reason for `--explain`
 * output. `reason` is null for included decisions, a {@see SkipReason} case otherwise.
 *
 * @internal
 */
final readonly class InclusionDecision
{
    /**
     * @param list<TraceEntry> $trace
     */
    public function __construct(
        public bool $included,
        public array $trace,
        public string $summary,
        public ?SkipReason $reason = null,
    ) {}
}
