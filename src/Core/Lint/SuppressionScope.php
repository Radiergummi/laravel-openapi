<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint;

/**
 * The reflection target a {@see SuppressionDirective} was collected from.
 *
 * Class and method scopes match findings by source file (and, for methods,
 * line range). Property scope matches structurally against the source class
 * and property recorded in a finding's context.
 */
enum SuppressionScope
{
    case ClassScope;
    case MethodScope;
    case PropertyScope;
}
