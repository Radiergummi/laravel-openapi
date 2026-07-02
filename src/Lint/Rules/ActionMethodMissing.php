<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;

/**
 * Registration stub for the `operation.action-method-missing` finding.
 *
 * Detection runs during spec generation in
 * {@see \Radiergummi\OpenApi\Support\Generator\Stages\PathsStage}: a route whose controller method
 * does not exist yields no operation, and the finding is emitted there. This class exists solely to
 * register the rule ID (so `#[IgnoreLint]`, severity overrides, and the lint catalog keep working).
 */
final class ActionMethodMissing implements Rule
{
    public string $id = self::ID;
    public Severity $severity = Severity::Degraded;
    public string $description = 'Route action resolves to a controller method that does not exist; the operation is documented but would fault at runtime.';

    public const string ID = 'operation.action-method-missing';
}
