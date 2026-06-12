<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\AssignOp\Coalesce as CoalesceAssignment;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\Closure as ClosureExpression;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

use function is_array;

/**
 * Predicate search over method-body statements with an explicit conditional-context policy —
 * the shared matching primitive for Tier-1 call-shape whitelists (see epic #5).
 *
 * Under {@see ConditionalContextPolicy::SkipConditionalContexts}, only expression and return
 * statements participate, and the search never enters a sub-expression whose evaluation depends
 * on a runtime condition (closure bodies, ternary or `match` arms, `&&` / `||` / `??` operands).
 * Argument lists, array literals, call chains, and assignments are descended into — they execute
 * unconditionally as part of their statement.
 *
 * Under {@see ConditionalContextPolicy::IncludeConditionalContexts}, every node is searched,
 * including `if` bodies and closures.
 *
 * @internal
 */
final readonly class StatementNodeFinder
{
    private NodeFinder $nodeFinder;

    public function __construct()
    {
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * Returns the first node (in source order) matching the predicate, or null.
     *
     * @param list<Stmt>          $statements
     * @param Closure(Node): bool $predicate
     */
    public function findFirst(array $statements, ConditionalContextPolicy $policy, Closure $predicate): ?Node
    {
        if ($policy === ConditionalContextPolicy::IncludeConditionalContexts) {
            return $this->nodeFinder->findFirst($statements, $predicate);
        }

        foreach ($statements as $statement) {
            $expression = match (true) {
                $statement instanceof Expression => $statement->expr,
                $statement instanceof Return_ => $statement->expr,
                default => null,
            };

            if ($expression === null) {
                continue;
            }

            $found = $this->findFirstUnconditional($expression, $predicate);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Depth-first search that refuses to enter nodes opening a conditional context.
     *
     * @param Closure(Node): bool $predicate
     */
    private function findFirstUnconditional(Node $node, Closure $predicate): ?Node
    {
        if ($this->opensConditionalContext($node)) {
            return null;
        }

        if ($predicate($node)) {
            return $node;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            /** @var mixed $children */
            $children = $node->{$subNodeName};

            foreach (is_array($children) ? $children : [$children] as $child) {
                if (!$child instanceof Node) {
                    continue;
                }

                $found = $this->findFirstUnconditional($child, $predicate);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Whether evaluating (parts of) this node depends on a runtime condition. The node is
     * skipped wholesale — even its unconditionally-evaluated parts (a ternary's condition, a
     * short-circuit's left operand) — matching there would document bizarre code.
     */
    private function opensConditionalContext(Node $node): bool
    {
        return $node instanceof ClosureExpression
            || $node instanceof ArrowFunction
            || $node instanceof Ternary
            || $node instanceof Match_
            || $node instanceof BooleanAnd
            || $node instanceof BooleanOr
            || $node instanceof LogicalAnd
            || $node instanceof LogicalOr
            || $node instanceof Coalesce
            || $node instanceof CoalesceAssignment;
    }
}
