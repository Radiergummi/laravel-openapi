<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Visitors;

use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

/**
 * A lint rule that inspects raw {@see ActionDescriptor} instances rather than the generated spec
 * tree. Used for routes that never reach the tree (e.g., hidden routes that still need to be
 * checked for misconfigured visibility attributes).
 */
interface RouteRule extends Visitor
{
    /**
     * @return iterable<Finding>
     */
    public function checkRoute(ActionDescriptor $descriptor, LintContext $context): iterable;
}
