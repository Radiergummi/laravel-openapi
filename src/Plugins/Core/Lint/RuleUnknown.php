<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Lint;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\RuleRegistry;
use Radiergummi\OpenApi\Support\Extraction\ValidationRulesToSchema;

/**
 * Registration stub for the `rule.unknown` finding ID. Detection happens in
 * {@see ValidationRulesToSchema}; this class exists only so the ID is known to
 * {@see RuleRegistry} for severity overrides, suppression, and the lint catalog.
 */
final class RuleUnknown implements Rule
{
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
