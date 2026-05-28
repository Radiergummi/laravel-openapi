<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Inclusion;

/**
 * One line in an inclusion-decision trace.
 *
 * Composed by {@see InclusionEvaluator} and rendered by the `openapi:why` command and
 * `openapi:generate --explain`. Pure data — no formatting decisions live here.
 *
 * `stage` identifies the conceptual phase (e.g. `'global-filter'`, `'spec-attribute'`,
 * `'spec-match'`, `'visibility'`); `name` identifies the specific thing under that stage
 * (the filter class name, the matched key, etc.); `passed` is the boolean outcome; `reason`
 * is a one-line human-readable explanation.
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
