<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\Return_;
use ReflectionMethod;

use function count;
use function in_array;
use function is_array;

/**
 * Locates the single `return [...]` array literal of a method body — the canonical shape of an
 * API Resource's `toArray()` (#12) and a Fractal transformer's `transform()` (#13).
 *
 * The bounded case (Tier-1, epic #5): within the first {@see self::STATEMENT_LIMIT} top-level
 * statements, the method contains exactly one `return` statement, that statement sits at the top
 * level (not inside an `if` guard — an early return means more than one possible shape), and its
 * expression is an array literal. Returns inside nested function-like scopes (closure values,
 * anonymous classes) belong to a different body and are ignored. Anything else returns null;
 * callers degrade gracefully.
 *
 * @internal
 */
final readonly class SingleReturnArrayLiteralFinder
{
    public const int STATEMENT_LIMIT = 10;

    public function __construct(
        private MethodBodyScanner $scanner,
    ) {}

    public function find(ReflectionMethod $method): ?Array_
    {
        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);

        if ($statements === []) {
            return null;
        }

        /** @var list<Return_> $returns */
        $returns = [];

        foreach ($statements as $statement) {
            $this->collectReturns($statement, $returns);
        }

        if (count($returns) !== 1) {
            return null;
        }

        $return = $returns[0];

        if (!in_array($return, $statements, strict: true)) {
            return null;
        }

        return $return->expr instanceof Array_ ? $return->expr : null;
    }

    /**
     * Depth-first collection of `return` statements, skipping nested function-like scopes —
     * a `return` inside a closure value or an anonymous-class method is not a return of the
     * scanned method.
     *
     * @param list<Return_> $returns
     */
    private function collectReturns(Node $node, array &$returns): void
    {
        if ($node instanceof FunctionLike) {
            return;
        }

        if ($node instanceof Return_) {
            $returns[] = $node;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            /** @var mixed $children */
            $children = $node->{$subNodeName};

            foreach (is_array($children) ? $children : [$children] as $child) {
                if ($child instanceof Node) {
                    $this->collectReturns($child, $returns);
                }
            }
        }
    }
}
