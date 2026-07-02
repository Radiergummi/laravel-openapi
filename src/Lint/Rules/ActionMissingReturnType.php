<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;

/**
 * Registration stub for the `operation.return-type-missing` finding.
 *
 * Detection runs during spec generation in
 * {@see \Radiergummi\OpenApi\Support\Generator\OperationBuilder}: when no resolver produced a
 * response, no `#[Response]`/`#[ResponseResource]` is present, and the return type is absent or
 * `mixed`/`void`/`never`, the finding is emitted at the failure site with its reason. This class
 * exists solely to register the rule ID (so `#[IgnoreLint]`, severity overrides, and the lint
 * catalog keep working).
 */
final class ActionMissingReturnType implements Rule
{
    public string $id = 'operation.return-type-missing';
    public Severity $severity = Severity::Inconsistent;
    public string $description = 'Action has no typed return value or response attribute, so no response schema can be inferred.';
}
