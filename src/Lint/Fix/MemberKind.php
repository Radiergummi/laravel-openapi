<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

/**
 * Whether a {@see RemoveAttributeFixer} targets a method or a property (including promoted parameters).
 */
enum MemberKind
{
    case Method;

    case Property;
}
