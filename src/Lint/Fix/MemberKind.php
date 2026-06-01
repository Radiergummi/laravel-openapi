<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

/**
 * Which kind of class member a {@see RemoveAttributeFixer} targets, so it knows whether to reflect
 * a method or a property (including a promoted constructor parameter).
 */
enum MemberKind
{
    case Method;

    case Property;
}
