<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Visitors;

use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\MetaSuppressionStale;
use Radiergummi\OpenApi\Lint\Tree\SpecTreeWalker;

/**
 * Rules that require post-walk execution (e.g., access to the full findings set).
 *
 * Not dispatched by {@see SpecTreeWalker}; the command invokes them separately after the walk.
 * Do not register in `openapi.lint.rules` unless also implementing a walk-phase visitor interface.
 *
 * @see MetaSuppressionStale
 */
interface PostWalkRule extends Visitor
{
    /**
     * @param list<Finding> $walkFindings All findings produced during the tree walk
     *
     * @return iterable<Finding>
     */
    public function check(LintContext $context, array $walkFindings): iterable;
}
