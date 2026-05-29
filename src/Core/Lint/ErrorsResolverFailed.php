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
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Core\Stages\ErrorResponseInferenceStage;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\RuleRegistry;

/**
 * Registration stub for the `errors.resolver-failed` finding.
 *
 * The actual detection runs during spec generation in {@see ErrorResponseInferenceStage}: when
 * a registered {@see \Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver} throws
 * while resolving an error body, the stage catches it, emits this rule ID into the
 * {@see FindingsCollector}, and continues the chain so one bad resolver does not abort the
 * generation run.
 *
 * This class exists solely to register the rule ID with the {@see RuleRegistry} so that:
 * - `#[IgnoreLint('errors.resolver-failed')]` is not flagged by `meta.unknown-rule`
 * - severity overrides in `config/openapi.lint.severity_overrides` apply
 * - the ID appears in the lint catalog
 */
final class ErrorsResolverFailed implements Rule
{
    /** fixHint: emitted by {@see ErrorResponseInferenceStage} alongside every errors.resolver-failed finding. */
    public const string FIX_HINT = 'Fix the throwing ErrorResponseResolver — implementations must catch internally and return null on failure.';

    #[Override]
    public function id(): string
    {
        return 'errors.resolver-failed';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'An ErrorResponseResolver threw while resolving an error response body; the chain continued and a bodyless response was emitted.';
    }
}
