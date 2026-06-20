<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Lint;

use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
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
    public string $id = 'rule.invalid-enum-value';
    public Severity $severity = Severity::Underspecified;
    public string $description = 'A SelfDocumentingRule returned a non-scalar enum value; the entry was dropped.';



}
