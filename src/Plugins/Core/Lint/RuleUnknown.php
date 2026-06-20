<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Lint;

use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\RuleRegistry;
use Radiergummi\OpenApi\Support\Extraction\ValidationRulesToSchema;

/**
 * Registration stub for the `rule.unknown` finding ID. Detection happens in
 * {@see ValidationRulesToSchema}; this class exists only so the ID is known to
 * {@see RuleRegistry} for severity overrides, suppression, and the lint catalog.
 */
final class RuleUnknown implements Rule
{
    public string $id = 'rule.unknown';
    public Severity $severity = Severity::Underspecified;
    public string $description = 'A Laravel validation Rule object cannot be mapped to a JSON Schema constraint and was dropped.';



}
