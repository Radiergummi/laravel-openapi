<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

use PhpParser\Node\Expr;
use RuntimeException;

use function sprintf;

/**
 * Thrown by {@see AstLiteralEvaluator} when an expression is not a compile-time literal —
 * the signal for a Tier-1 reader to degrade gracefully instead of guessing.
 *
 * @internal
 */
final class NonLiteralValueException extends RuntimeException
{
    public static function for(Expr $expression): self
    {
        return new self(sprintf(
            'Expression of type %s is not a literal value.',
            $expression->getType(),
        ));
    }
}
