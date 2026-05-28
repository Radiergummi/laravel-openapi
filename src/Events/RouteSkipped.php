<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Events;

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Support\Inclusion\InclusionDecision;
use Radiergummi\OpenApi\Support\Inclusion\InclusionEvaluator;

/**
 * Dispatched once per (route × spec) pair the inclusion evaluator decides to exclude.
 *
 * Fires for every exclusion stage handled by {@see InclusionEvaluator}: global filters
 * (`config('openapi.filters')`, including the bundled Telescope/Nova/Ignition/Passport
 * skippers), spec membership (`#[Spec]` or the spec's `match` config), and visibility.
 *
 * `summary` is the human-readable text from {@see InclusionDecision::$summary} — display-only;
 * branch on `reason` for stable behaviour.
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
