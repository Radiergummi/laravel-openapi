<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix\Ast;

/**
 * The kind of class member an {@see AstOperation} targets.
 *
 * @internal
 */
enum TargetKind
{
    case Method;

    case Property;
}
