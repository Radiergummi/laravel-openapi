<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Variable;
use ReflectionMethod;

use function count;
use function in_array;

/**
 * Locates the single top-level `return [...]` in a method body; the canonical shape for API
 * Resource `toArray()` and Fractal `transform()`. A `return $variable;` resolves the same way when
 * that variable has exactly one unconditional array-literal assignment. Returns null if the method
 * has zero or multiple top-level returns, or the return cannot be reduced to a single array
 * literal. Callers degrade gracefully.
 *
 * @internal
 */
final readonly class SingleReturnArrayLiteralFinder
{
    /**
     * Pathological-input backstop, not a semantic bound: the guard that makes a resolution sound is
     * "exactly one unconditional return", not how far the scan looked. Set far above ordinary method
     * length so an everyday run of guard clauses never hides the trailing return.
     */
    public const int RETURN_SCAN_STATEMENT_LIMIT = 100;

    public function __construct(
        private MethodBodyScanner $scanner,
        private ReturnExpressionResolver $returnExpressionResolver = new ReturnExpressionResolver(),
    ) {}

    public function find(ReflectionMethod $method): ?Array_
    {
        $statements = $this->scanner->firstStatements($method, self::RETURN_SCAN_STATEMENT_LIMIT);

        if ($statements === []) {
            return null;
        }

        $returns = $this->returnExpressionResolver->methodLevelReturns($statements);

        if (count($returns) !== 1) {
            return null;
        }

        $return = $returns[0];

        if (!in_array($return, $statements, strict: true)) {
            return null;
        }

        if ($return->expr instanceof Array_) {
            return $return->expr;
        }

        if ($return->expr instanceof Variable) {
            $resolution = $this->returnExpressionResolver->resolveVariable($return->expr, $statements);

            return $resolution->expression instanceof Array_ ? $resolution->expression : null;
        }

        return null;
    }
}
