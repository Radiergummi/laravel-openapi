<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules\Visitors;

use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;

/**
 * Marker interface for rules that require post-walk execution.
 *
 * Post-walk rules need information not available during the tree walk—such as
 * the complete set of findings from all other rules or cross-references that
 * are only fully populated after traversal. They are NOT dispatched by
 * {@see \Radiergummi\OpenApi\Core\Lint\Tree\SpecTreeWalker} and must be invoked
 * separately by the command after the walk completes.
 *
 * Rules implementing this interface should NOT be registered in the container
 * tag `openapi.lint.rules` unless they also implement a visitor interface for
 * the walk phase.
 *
 * @see \Radiergummi\OpenApi\Core\Lint\Rules\MetaSuppressionStale
 */
interface PostWalkRule
{
    /**
     * Execute the rule after all tree-walk rules have completed.
     *
     * @param list<Finding> $walkFindings All findings produced during the tree walk
     *
     * @return iterable<Finding>
     */
    public function check(LintContext $context, array $walkFindings): iterable;
}
