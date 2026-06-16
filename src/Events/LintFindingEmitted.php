<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Events;

use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\LintRunner;

/**
 * Dispatched whenever a {@see FindingsCollector} accepts a finding (both generation and lint runs).
 * The finding's `spec` field is null outside lint runs; {@see LintRunner} sets it when draining.
 */
final readonly class LintFindingEmitted
{
    public function __construct(
        public Finding $finding,
    ) {}
}
