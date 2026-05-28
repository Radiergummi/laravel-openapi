<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint;

use Override;
use Radiergummi\OpenApi\Contracts\Extraction\SelfDocumentingRule;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Core\Extraction\ValidationRulesToSchema;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\RuleRegistry;
use Radiergummi\OpenApi\Support\Extraction\RuleDocumentation;

/**
 * Registration stub for the `rule.invalid-enum-value` finding.
 *
 * Detection runs during spec generation in {@see ValidationRulesToSchema}: when a
 * {@see SelfDocumentingRule} returns a {@see RuleDocumentation} whose `$enum` list contains a
 * value that is neither int, float, nor string, the offending entries are dropped and this rule
 * ID is emitted directly into the {@see FindingsCollector}.
 *
 * This class exists solely to register the rule ID with the {@see RuleRegistry} so that:
 * - `#[IgnoreLint('rule.invalid-enum-value')]` is not flagged by `meta.unknown-rule`
 * - severity overrides in `config/openapi.lint.severity_overrides` apply
 * - the ID appears in the lint catalog
 */
final class RuleInvalidEnumValue implements Rule
{
    /** fixHint: emitted by ValidationRulesToSchema alongside every rule.invalid-enum-value finding. */
    public const string FIX_HINT = 'Return enum values as int|float|string from RuleDocumentation::$enum.';

    #[Override]
    public function id(): string
    {
        return 'rule.invalid-enum-value';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'A SelfDocumentingRule returned a non-scalar enum value; the entry was dropped.';
    }
}
