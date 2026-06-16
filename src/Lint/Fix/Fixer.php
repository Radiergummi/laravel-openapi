<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Radiergummi\OpenApi\Lint\Finding;

/**
 * Converts a {@see Finding} into zero or more mechanical {@see Fix}es.
 *
 * Implementations are pure: read source via `$context`, return edits without writing. A fixer
 * that cannot unambiguously resolve a finding yields nothing.
 */
interface Fixer
{
    /**
     * @return iterable<Fix>
     */
    public function fix(Finding $finding, FixContext $context): iterable;
}
