<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;

/**
 * Registration stub for the `rule.unknown` finding.
 *
 * The actual detection runs during spec generation in
 * {@see \Radiergummi\OpenApi\Core\Extractors\ValidationRulesToSchema}: when a
 * Laravel validation `Rule` object is encountered that the extractor does not
 * know how to map to a JSON Schema constraint, it emits this rule ID directly
 * into the {@see \Radiergummi\OpenApi\Core\Lint\FindingsCollector}.
 *
 * This class exists solely to register the rule ID with the
 * {@see \Radiergummi\OpenApi\Core\Lint\RuleRegistry} so that:
 * - `#[IgnoreLint('rule.unknown')]` is not flagged by `meta.unknown-rule`
 * - severity overrides in `config/openapi.lint.severity_overrides` apply
 * - the ID appears in the lint catalog
 */
final class RuleUnknown implements Rule
{
    /** fixHint: emitted by ValidationRulesToSchema alongside every rule.unknown finding. */
    public const string FIX_HINT = 'Register a transformSchema hook to inject the constraint, or extend ValidationRulesToSchema.';

    #[Override]
    public function id(): string
    {
        return 'rule.unknown';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'A Laravel validation Rule object cannot be mapped to a JSON Schema constraint and was dropped.';
    }
}
