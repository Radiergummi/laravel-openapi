<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix\Ast;

/**
 * The kind of node an {@see AstOperation} targets within its declaring class.
 *
 * `ClassNode` addresses the class declaration itself (for attributes on the class); `Method` and
 * `Property` address a named member (the latter also matching a promoted constructor parameter).
 *
 * @internal
 */
enum TargetKind
{
    case ClassNode;

    case Method;

    case Property;
}
