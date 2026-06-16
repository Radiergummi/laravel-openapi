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

use function array_values;
use function is_array;

/**
 * Predicate search over method-body statements, with an explicit conditional-context policy.
 *
 * Under {@see ConditionalContextPolicy::SkipConditionalContexts}, the search only visits
 * expression and return statements and never enters sub-expressions whose evaluation depends
 * on a runtime condition (closures, ternary/match arms, `&&`/`||`/`??`). Under
 * {@see ConditionalContextPolicy::IncludeConditionalContexts}, every node is visited.
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
     * Whether this node introduces a conditional context. The whole node is skipped, including
     * unconditionally-evaluated sub-nodes, to avoid documenting conditionally-reached code.
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

    /**
     * Returns every node (in source order) matching the predicate.
     *
     * @param list<Stmt>          $statements
     * @param Closure(Node): bool $predicate
     *
     * @return list<Node>
     */
    public function findAll(array $statements, ConditionalContextPolicy $policy, Closure $predicate): array
    {
        if ($policy === ConditionalContextPolicy::IncludeConditionalContexts) {
            return array_values($this->nodeFinder->find($statements, $predicate));
        }

        $found = [];

        foreach ($statements as $statement) {
            $expression = match (true) {
                $statement instanceof Expression => $statement->expr,
                $statement instanceof Return_ => $statement->expr,
                default => null,
            };

            if ($expression === null) {
                continue;
            }

            $this->collectUnconditional($expression, $predicate, $found);
        }

        return $found;
    }

    /**
     * Like {@see findFirstUnconditional} but accumulates all matches instead of short-circuiting.
     *
     * @param Closure(Node): bool $predicate
     * @param list<Node>          $found
     */
    private function collectUnconditional(Node $node, Closure $predicate, array &$found): void
    {
        if ($this->opensConditionalContext($node)) {
            return;
        }

        if ($predicate($node)) {
            $found[] = $node;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            /** @var mixed $children */
            $children = $node->{$subNodeName};

            foreach (is_array($children) ? $children : [$children] as $child) {
                if ($child instanceof Node) {
                    $this->collectUnconditional($child, $predicate, $found);
                }
            }
        }
    }
}
