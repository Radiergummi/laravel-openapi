<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules;

use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;

/**
 * Registration stub for the `resource.response-empty` finding.
 *
 * Detection runs during spec generation in
 * {@see \Radiergummi\OpenApi\Plugins\ApiResources\Resolvers\ResourceResponseResolver}: when a
 * resource response resolves to the base or an abstract `JsonResource` with no inferable shape,
 * the finding is emitted there (it ships an empty `{data: {}}` envelope). This class exists solely
 * to register the rule ID (so `#[IgnoreLint]`, severity overrides, and the lint catalog keep
 * working).
 */
final class ResourceResponseEmpty implements Rule
{
    public string $id = 'resource.response-empty';
    public Severity $severity = Severity::Degraded;
    public string $description = 'A resource response resolves to the base or an abstract JsonResource with no inferable '
        . 'shape. It ships an empty {data: {}} envelope.';
}
