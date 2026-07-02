<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;

/**
 * Registration stub for the `throws.transitive-missing` finding.
 *
 * Detection runs during spec generation in
 * {@see \Radiergummi\OpenApi\Plugins\Core\ErrorContributors\ThrowsErrorContributor}, which already
 * walks `@throws`: when an Action `handle()` declares `@throws` exceptions the controller method
 * does not redeclare, the finding is emitted there. This class exists solely to register the rule
 * ID (so `#[IgnoreLint]`, severity overrides, and the lint catalog keep working).
 */
final class ThrowsTransitiveMissing implements Rule
{
    public string $id = 'throws.transitive-missing';
    public Severity $severity = Severity::Degraded;
    public string $description = 'Action::handle() declares @throws exceptions not redeclared on the controller method.';
}
