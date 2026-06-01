<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Events;

use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\LintRunner;

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
