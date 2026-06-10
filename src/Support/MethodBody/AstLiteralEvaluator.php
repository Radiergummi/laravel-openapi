<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;

use function is_float;
use function is_int;
use function is_string;

/**
 * Evaluates a php-parser expression made up purely of compile-time literals: scalars,
 * `true` / `false` / `null`, (nested) array literals, `::class` constants, and negated numbers.
 * Anything dynamic — variables, calls, concatenations, object instantiation — throws
 * {@see NonLiteralValueException}; callers degrade gracefully rather than guess.
 *
 * @internal
 */
final readonly class AstLiteralEvaluator
{
    /**
     * @throws NonLiteralValueException
     */
    public static function evaluate(Expr $expression): mixed
    {
        return match (true) {
            $expression instanceof String_ => $expression->value,
            $expression instanceof Int_ => $expression->value,
            $expression instanceof Float_ => $expression->value,
            $expression instanceof ConstFetch => self::evaluateConstant($expression),
            $expression instanceof Array_ => self::evaluateArray($expression),
            $expression instanceof ClassConstFetch => self::evaluateClassConstant($expression),
            $expression instanceof UnaryMinus => self::evaluateNegation($expression),
            default => throw NonLiteralValueException::for($expression),
        };
    }

    /**
     * @throws NonLiteralValueException
     */
    private static function evaluateConstant(ConstFetch $expression): ?bool
    {
        return match ($expression->name->toLowerString()) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => throw NonLiteralValueException::for($expression),
        };
    }

    /**
     * @return array<int|string, mixed>
     *
     * @throws NonLiteralValueException
     */
    private static function evaluateArray(Array_ $expression): array
    {
        $values = [];

        foreach ($expression->items as $item) {
            if ($item->unpack || $item->byRef) {
                throw NonLiteralValueException::for($expression);
            }

            $value = self::evaluate($item->value);

            if ($item->key === null) {
                $values[] = $value;

                continue;
            }

            $key = self::evaluate($item->key);

            if (!is_int($key) && !is_string($key)) {
                throw NonLiteralValueException::for($item->key);
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * Resolves `Some\Class::class` to its fully-qualified class-name string. Other class
     * constants would require loading the class, so they count as non-literal.
     *
     * @throws NonLiteralValueException
     */
    private static function evaluateClassConstant(ClassConstFetch $expression): string
    {
        if (
            $expression->class instanceof Name
            && $expression->name instanceof Identifier
            && $expression->name->toLowerString() === 'class'
        ) {
            return $expression->class->toString();
        }

        throw NonLiteralValueException::for($expression);
    }

    /**
     * @throws NonLiteralValueException
     */
    private static function evaluateNegation(UnaryMinus $expression): int|float
    {
        $value = self::evaluate($expression->expr);

        if (!is_int($value) && !is_float($value)) {
            throw NonLiteralValueException::for($expression);
        }

        return -$value;
    }
}
