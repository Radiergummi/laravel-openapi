<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules;

use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;

/**
 * Registration stub for the `resource.fields-undeclared` finding.
 *
 * Detection runs during spec generation in
 * {@see \Radiergummi\OpenApi\Plugins\ApiResources\Resolvers\ResourceResponseResolver}: when a
 * concrete API Resource used as a response declares no `#[ResourceField]` and its shape cannot be
 * inferred from `toArray()` or a wrapped model, the finding is emitted there. This class exists
 * solely to register the rule ID (so `#[IgnoreLint]`, severity overrides, and the lint catalog
 * keep working).
 */
final class ResourceFieldsUndeclared implements Rule
{
    public string $id = 'resource.fields-undeclared';
    public Severity $severity = Severity::Degraded;
    public string $description = 'An API Resource used as a response declares no #[ResourceField] attributes '
        . 'and its shape cannot be inferred from toArray() or a wrapped model.';
}
