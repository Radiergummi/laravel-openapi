<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Inclusion;

use Radiergummi\OpenApi\Events\SkipReason;

/**
 * Result of {@see InclusionEvaluator::decide()} for one (route × spec) pair.
 *
 * `included` is the final yes/no the generator reads. `trace` is the structured list of
 * checks that produced it; `summary` is a one-line text reason suitable for `--explain`
 * output (the leading verb explaining the outcome, e.g. "global filter SkipNovaRoutes",
 * "matched by prefix", "hidden in environment local"). `reason` is `null` for included
 * decisions and a {@see SkipReason} enum case for excluded ones.
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
