<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Lint;

use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Plugins\Core\ErrorContributors\ThrowsErrorContributor;

/**
 * Registration stub for the `throws.unmapped` finding.
 *
 * Detection runs in {@see ThrowsErrorContributor}; this stub registers the rule ID so that
 * `#[IgnoreLint]`, severity overrides, and the lint catalog all work correctly.
 */
final class ThrowsUnmapped implements Rule
{
    public string $id = 'throws.unmapped';
    public Severity $severity = Severity::Underspecified;
    public string $description = 'A @throws FQCN has no entry in the exception map or #[ExceptionResponse] attribute.';



}
