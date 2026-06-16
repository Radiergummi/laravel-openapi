<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Lint;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Support\Extraction\ValidationRulesToSchema;

/**
 * Registration stub for the `rule.invalid-enum-value` finding.
 *
 * Detection runs in {@see ValidationRulesToSchema}: non-scalar enum values are dropped and this
 * rule ID is emitted into the {@see FindingsCollector}. This class exists only to register the
 * rule ID so suppressions, severity overrides, and the lint catalog work correctly.
 */
final class RuleInvalidEnumValue implements Rule
{
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
