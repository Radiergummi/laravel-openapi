<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Lint;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Plugins\Core\ErrorContributors\ThrowsErrorContributor;

/**
 * Registration stub for the `throws.unmapped` finding.
 *
 * Detection runs in {@see ThrowsErrorContributor}; this stub registers the rule ID so that
 * `#[IgnoreLint]`, severity overrides, and the lint catalog all work correctly.
 */
final class ThrowsUnmapped implements Rule
{
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
