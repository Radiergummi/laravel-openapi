<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

use PhpParser\Node\Expr;

/**
 * The outcome of resolving a returned variable through its single unconditional assignment: either
 * the assigned expression, or the reason it could not be reduced to one. Exactly one of the two is
 * set.
 *
 * @internal
 */
final readonly class ReturnVariableResolution
{
    private function __construct(
        public ?Expr $expression,
        public ?ReturnVariableRefusal $refusal,
    ) {}

    public static function resolved(Expr $expression): self
    {
        return new self($expression, null);
    }

    public static function refused(ReturnVariableRefusal $refusal): self
    {
        return new self(null, $refusal);
    }
}
