<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Support\Generator\Stages\ErrorResponseInferenceStage;

/**
 * Registration stub for the `errors.resolver-failed` finding emitted by
 * {@see ErrorResponseInferenceStage}. Registers the rule ID so suppression, severity overrides,
 * and the lint catalog work correctly.
 */
final class ErrorsResolverFailed implements Rule
{
    /**
     * fixHint: alias for {@see ErrorResponseInferenceStage::RESOLVER_FAILED_FIX_HINT}. The
     * stage emits this hint with every `errors.resolver-failed` finding; the alias here lets
     * external callers reference it via the rule class without crossing the Lint → Support
     * namespace.
     */
    public const string FIX_HINT = ErrorResponseInferenceStage::RESOLVER_FAILED_FIX_HINT;

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
