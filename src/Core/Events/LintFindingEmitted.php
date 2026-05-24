<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Events;

use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Lint\LintRunner;

/**
 * Dispatched whenever a {@see FindingsCollector} accepts a finding — both extractor-emitted
 * (during generation) and rule-emitted (during lint runs).
 *
 * The finding's `spec` field is `null` outside lint runs; the {@see LintRunner} tags it with
 * the spec name only when draining the per-spec collector.
 */
final readonly class LintFindingEmitted
{
    public function __construct(
        public Finding $finding,
    ) {}
}
