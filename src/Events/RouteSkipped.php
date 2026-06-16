<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Events;

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Support\Inclusion\InclusionDecision;
use Radiergummi\OpenApi\Support\Inclusion\InclusionEvaluator;

/**
 * Dispatched for each (route x spec) pair excluded by {@see InclusionEvaluator}: global filters,
 * spec membership, and visibility. Branch on `reason` for stable behaviour; `summary` is
 * display-only text from {@see InclusionDecision::$summary}.
 */
final readonly class RouteSkipped
{
    public function __construct(
        public Route $route,
        public string $spec,
        public SkipReason $reason,
        public string $summary,
    ) {}
}
