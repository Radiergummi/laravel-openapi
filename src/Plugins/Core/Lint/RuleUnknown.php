<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Lint;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\RuleRegistry;
use Radiergummi\OpenApi\Support\Extraction\ValidationRulesToSchema;

/**
 * Registration stub for the `rule.unknown` finding.
 *
 * Detection runs during spec generation in {@see ValidationRulesToSchema}: when a Laravel
 * validation `Rule` object is encountered that the extractor cannot map to a JSON Schema
 * constraint, it emits this rule ID directly into the {@see FindingsCollector}.
 *
 * This class exists solely to register the rule ID with the {@see RuleRegistry} so that:
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
