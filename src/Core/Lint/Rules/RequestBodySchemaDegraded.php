<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Extractors\SchemaFromFormRequest;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Lint\RuleRegistry;

/**
 * Registration stub for the `request-body.schema-degraded` finding.
 *
 * Detection runs during spec generation in {@see SchemaFromFormRequest}: when instantiating
 * the FormRequest or calling `rules()` throws, the schema is registered as a placeholder and
 * this rule ID is emitted directly into the {@see FindingsCollector}.
 *
 * This class exists solely to register the rule ID with the {@see RuleRegistry} so that:
 * - `#[IgnoreLint('request-body.schema-degraded')]` is not flagged by `meta.unknown-rule`
 * - severity overrides in `config/openapi.lint.severity_overrides` apply
 * - the ID appears in the lint catalog
 */
final class RequestBodySchemaDegraded implements Rule
{
    /**
     * fixHint: emitted by SchemaFromFormRequest alongside every finding.
     */
    public const string FIX_HINT = 'Avoid container access in FormRequest::rules() (auth, route, DI). If the rules genuinely require runtime state, model the schema with a Data class instead.';

    #[Override]
    public function id(): string
    {
        return 'request-body.schema-degraded';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'A FormRequest threw during introspection; its request body schema is a placeholder and does not reflect the real validation rules.';
    }
}
