<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

/**
 * The reflection target a {@see SuppressionDirective} was collected from.
 *
 * Class scope matches findings structurally via the source class recorded in a finding's context;
 * method scope matches by source file and line range. Property scope matches structurally against
 * the source class and property recorded in a finding's context.
 */
enum SuppressionScope
{
    case ClassScope;
    case MethodScope;
    case PropertyScope;
}
