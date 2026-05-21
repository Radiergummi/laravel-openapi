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
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;

/**
 * A lint rule that inspects raw {@see ActionDescriptor} instances rather
 * than the generated spec tree. Used for routes that never reach the tree
 * (e.g. hidden routes that still need to be checked for misconfigured
 * visibility attributes).
 */
interface RouteRule
{
    /**
     * @return iterable<Finding>
     */
    public function checkRoute(ActionDescriptor $descriptor, LintContext $context): iterable;
}
