<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Lint;

/**
 * A lint rule registered with {@see RuleRegistry} and run by the lint pipeline.
 *
 * Implement one or more visitor interfaces (e.g. {@see OperationRule}) to receive tree-walk
 * callbacks. Every rule must declare a stable `id()` for config overrides and `#[IgnoreLint]`.
 */
interface Rule
{
    /**
     * Stable, dot-namespaced identifier (e.g. `operation.id-missing`); must be unique.
     */
    public function id(): string;

    /**
     * Severity of the findings this rule emits. Lower is more severe.
     */
    public function severity(): Severity;

    /**
     * One-line description of what the rule checks; shown by `openapi:lint --list`.
     */
    public function description(): string;
}
