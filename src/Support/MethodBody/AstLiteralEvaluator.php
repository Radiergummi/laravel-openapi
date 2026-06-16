<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

use Error;
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

use function array_all;
use function class_exists;
use function constant;
use function interface_exists;
use function is_array;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;

/**
 * Evaluates a php-parser expression made up purely of compile-time literals: scalars,
 * `true`/`false`/`null`, (nested) array literals, class constants, and negated numbers.
 * Anything dynamic throws {@see NonLiteralValueException}; callers degrade gracefully.
 *
 * Class constants resolve when the class autoloads and the constant's value is itself a literal
 * (enum cases and arrays containing objects throw). Unresolved `self::`/`static::` also throw.
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
     * Resolves `Class::class` to its FQCN string, or any other constant to its literal value.
     * Triggers autoloading intentionally (the generator already autoloads app classes).
     *
     * @throws NonLiteralValueException
     */
    private static function evaluateClassConstant(ClassConstFetch $expression): mixed
    {
        if (!$expression->class instanceof Name || !$expression->name instanceof Identifier) {
            throw NonLiteralValueException::for($expression);
        }

        $class = $expression->class->toString();

        if ($expression->name->toLowerString() === 'class') {
            return $class;
        }

        // Unresolved `self::` / `static::` references fail the existence check naturally.
        if (!class_exists($class) && !interface_exists($class)) {
            throw NonLiteralValueException::for($expression);
        }

        try {
            $value = constant($class . '::' . $expression->name->toString());
        } catch (Error) {
            // Undefined constant on a loaded class.
            throw NonLiteralValueException::for($expression);
        }

        if (!self::isLiteralValue($value)) {
            throw NonLiteralValueException::for($expression);
        }

        return $value;
    }

    private static function isLiteralValue(mixed $value): bool
    {
        if (is_array($value)) {
            return array_all($value, static fn(mixed $element): bool => self::isLiteralValue($element));
        }

        return $value === null || is_scalar($value);
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
