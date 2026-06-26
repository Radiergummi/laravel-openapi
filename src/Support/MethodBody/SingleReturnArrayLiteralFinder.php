<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Return_;
use ReflectionMethod;

use function count;
use function in_array;
use function is_array;
use function is_string;

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
    public const int STATEMENT_LIMIT = 10;

    private StatementNodeFinder $statementNodeFinder;

    public function __construct(
        private MethodBodyScanner $scanner,
    ) {
        $this->statementNodeFinder = new StatementNodeFinder();
    }

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

        if ($return->expr instanceof Array_) {
            return $return->expr;
        }

        if ($return->expr instanceof Variable) {
            return $this->arrayLiteralAssignedTo($return->expr, $statements);
        }

        return null;
    }

    /**
     * The array literal a returned variable is assigned, when it is assigned exactly once on the
     * unconditional path and that assignment's value is an array literal. Refuses a dynamically-
     * named variable, zero or multiple assignments, a conditional assignment, or a non-literal
     * value.
     *
     * Any further unconditional write that mutates the variable after the literal (an element
     * write `$data['k'] = …` or a compound assignment like `$data += [...]`) also refuses: the
     * base literal alone would understate the value, so the caller's richer fallback (e.g. the
     * wrapped model schema) is the honest answer. The same writes guarded behind a condition stay
     * unread, leaving the base literal as a never-wrong subset of the genuinely-present fields.
     *
     * @param list<Stmt> $statements
     */
    private function arrayLiteralAssignedTo(Variable $variable, array $statements): ?Array_
    {
        $variableName = $variable->name;

        if (!is_string($variableName)) {
            return null;
        }

        $isAssignmentToVariable = static fn(Node $node): bool
            => $node instanceof Assign
            && $node->var instanceof Variable
            && $node->var->name === $variableName;

        $allAssignments = $this->statementNodeFinder->findAll(
            $statements,
            ConditionalContextPolicy::IncludeConditionalContexts,
            $isAssignmentToVariable,
        );
        $unconditionalAssignments = $this->statementNodeFinder->findAll(
            $statements,
            ConditionalContextPolicy::SkipConditionalContexts,
            $isAssignmentToVariable,
        );

        if (count($allAssignments) !== 1 || count($unconditionalAssignments) !== 1) {
            return null;
        }

        $unconditionalMutations = $this->statementNodeFinder->findAll(
            $statements,
            ConditionalContextPolicy::SkipConditionalContexts,
            fn(Node $node): bool => $this->mutatesVariable($node, $variableName),
        );

        if ($unconditionalMutations !== []) {
            return null;
        }

        /** @var Assign $assignment */
        $assignment = $unconditionalAssignments[0];

        return $assignment->expr instanceof Array_ ? $assignment->expr : null;
    }

    /**
     * Whether the node mutates the named variable after its assignment: an element write
     * (`$data['k'] = …`, an `Assign` whose target is an array-dimension fetch rooted at the
     * variable) or any compound assignment (`$data += …`, `$data['k'] .= …`).
     */
    private function mutatesVariable(Node $node, string $variableName): bool
    {
        $target = match (true) {
            $node instanceof AssignOp => $node->var,
            $node instanceof Assign && $node->var instanceof ArrayDimFetch => $node->var,
            default => null,
        };

        while ($target instanceof ArrayDimFetch) {
            $target = $target->var;
        }

        return $target instanceof Variable && $target->name === $variableName;
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
