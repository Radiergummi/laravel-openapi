<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\RuleRegistry;
use Radiergummi\OpenApi\Support\Extraction\RequestBodyExtractor;

/**
 * Registration stub for the `request.empty` finding.
 *
 * The actual detection runs during spec generation in {@see RequestBodyExtractor}: When a
 * POST/PUT/PATCH action has no resolvable request-body schema, that extractor emits this rule ID
 * directly into the {@see FindingsCollector}.
 *
 * This class exists solely to register the rule ID with the {@see RuleRegistry} so that:
 * - `#[IgnoreLint('request.empty')]` is not flagged by `meta.unknown-rule`
 * - severity overrides in `config/openapi.lint.severity_overrides` apply
 * - the ID appears in the lint catalog
 */
final class RequestEmpty implements Rule
{
    public const string FIX_HINT = 'Type-hint a Data class or FormRequest on the controller or injected Action.';

    #[Override]
    public function id(): string
    {
        return 'request.empty';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'POST/PUT/PATCH action has no resolvable request-body schema. Add a Data class or FormRequest.';
    }
}
