<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Contracts\Lint;

/**
 * Linting Rule
 */
interface Rule
{
    /**
     * A unique identifier for the rule.
     *
     * The rule identifier is used to reference the rule in configuration or rule suppressions
     * and must be unique across all rules. It should be a short, human-readable string, ideally
     * prefixed with the rule category, and should not contain spaces or special characters.
     */
    public function id(): string;

    /**
     * The severity level of the rule, used to determine how findings are reported.
     *
     * The level is defined as an unbounded integer where lower values indicate more severe issues
     * starting at 0 (breaking the OpenAPI spec), followed by 1 (causing invalid documentation),
     * 2 (missing details), 3 (potential issues), 4 (unused features and style violations), and so
     * on.
     *
     * @return int<0, max>
     */
    public function level(): int;

    /**
     * A one-line human description of what the rule checks.
     *
     * Used by `openapi:lint --list` and the generated docs catalog.
     */
    public function description(): string;
}
