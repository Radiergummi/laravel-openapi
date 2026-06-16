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
 * Locates the single top-level `return [...]` in a method body; the canonical shape for API
 * Resource `toArray()` and Fractal `transform()`. Returns null if the method has zero or multiple
 * top-level returns, or the return expression is not an array literal. Callers degrade gracefully.
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
     * Depth-first return collection, skipping nested function-like scopes (closures, anon classes).
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
