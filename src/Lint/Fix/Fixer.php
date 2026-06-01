<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Radiergummi\OpenApi\Lint\Finding;

/**
 * Turns a single {@see Finding} into zero or more mechanical {@see Fix}es.
 *
 * Implementations are pure: they read the source through `$context` and return the edits they would
 * make without touching the filesystem (the {@see FixApplicator} writes). A fixer that cannot
 * unambiguously resolve a finding — e.g. the offending construct is not attribute-sourced — yields
 * nothing, leaving the finding to be reported as unfixed.
 */
interface Fixer
{
    /**
     * @return iterable<Fix>
     */
    public function fix(Finding $finding, FixContext $context): iterable;
}
