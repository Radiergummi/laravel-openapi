<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Support\Generator\Stages\ErrorResponseInferenceStage;

/**
 * Registration stub for the `errors.resolver-failed` finding emitted by
 * {@see ErrorResponseInferenceStage}. Registers the rule ID so suppression, severity overrides,
 * and the lint catalog work correctly.
 */
final class ErrorsResolverFailed implements Rule
{
    public string $id = 'errors.resolver-failed';
    public Severity $severity = Severity::Underspecified;
    public string $description = 'An ErrorResponseResolver threw while resolving an error response body; the chain continued and a bodyless response was emitted.';

    /**
     * fixHint: alias for {@see ErrorResponseInferenceStage::RESOLVER_FAILED_FIX_HINT}. The
     * stage emits this hint with every `errors.resolver-failed` finding; the alias here lets
     * external callers reference it via the rule class without crossing the Lint → Support
     * namespace.
     */
    public const string FIX_HINT = ErrorResponseInferenceStage::RESOLVER_FAILED_FIX_HINT;



}
