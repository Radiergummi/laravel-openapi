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
use Radiergummi\OpenApi\Core\Extractors\StandardResponsesExtractor;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Lint\RuleRegistry;

/**
 * Registration stub for the `throws.unmapped` finding.
 *
 * The actual detection runs during spec generation in {@see StandardResponsesExtractor}: when a
 * `@throws` FQCN has no matching entry in the exception map or `#[ExceptionResponse]` attribute,
 * that extractor emits this rule ID directly into the {@see FindingsCollector}.
 *
 * This class exists solely to register the rule ID with the {@see RuleRegistry} so that:
 * - `#[IgnoreLint('throws.unmapped')]` is not flagged by `meta.unknown-rule`
 * - severity overrides in `config/openapi.lint.severity_overrides` apply
 * - the ID appears in the lint catalog
 */
final class ThrowsUnmapped implements Rule
{
    /**
     * fixHint: emitted by {@see StandardResponsesExtractor} alongside every `throws.unmapped`
     * finding. The actual hint text is context-aware (app vs vendor exception) and built at emit
     * time.
     */
    public const string FIX_HINT = 'Add #[ExceptionResponse(status: ..., description: ...)] to the exception class, or register it in config/openapi.php (exception_responses map).';

    #[Override]
    public function id(): string
    {
        return 'throws.unmapped';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'A @throws FQCN has no entry in the exception map or #[ExceptionResponse] attribute.';
    }
}
