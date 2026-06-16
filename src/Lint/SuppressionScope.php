<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

/**
 * The reflection target a {@see SuppressionDirective} was collected from.
 */
enum SuppressionScope
{
    case ClassScope;
    case MethodScope;
    case PropertyScope;
}
