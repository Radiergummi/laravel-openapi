<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Return_;

use function count;
use function is_array;
use function is_string;

/**
 * Shared return-resolution primitive for the bounded body scanners.
 *
 * Two concerns the Data, API Resource, and array-literal readers each hand-rolled:
 *  - collecting the method's own top-level returns (skipping nested closures / anonymous classes);
 *  - reducing a `return $variable;` to the single expression the variable is assigned, refusing a
 *    dynamically-named variable, zero/multiple/conditional assignments, and — critically — any
 *    unconditional mutation *after* the assignment (an element write `$v['k'] = …` or a compound
 *    assignment `$v += …`), which would leave the base value stale.
 *
 * Log-free by design: refusals are returned as {@see ReturnVariableRefusal} so plugin readers can
 * apply their own logging without pushing that policy into the Support layer.
 *
 * @internal
 */
final readonly class ReturnExpressionResolver
{
    public function __construct(
        private StatementNodeFinder $statementNodeFinder = new StatementNodeFinder(),
    ) {}

    /**
     * The method's own return statements, in source order. Closures, arrow functions, and
     * anonymous classes open their own scope and are excluded.
     *
     * @param list<Stmt> $statements
     *
     * @return list<Return_>
     */
    public function methodLevelReturns(array $statements): array
    {
        $returns = [];

        foreach ($statements as $statement) {
            $this->collectReturns($statement, $returns);
        }

        return $returns;
    }

    /**
     * Resolves `return $variable;` through the variable's single unconditional assignment.
     *
     * @param list<Stmt> $statements
     */
    public function resolveVariable(Variable $variable, array $statements): ReturnVariableResolution
    {
        $variableName = $variable->name;

        if (!is_string($variableName)) {
            return ReturnVariableResolution::refused(ReturnVariableRefusal::DynamicallyNamedVariable);
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
            return ReturnVariableResolution::refused(ReturnVariableRefusal::NotAssignedOnce);
        }

        $unconditionalMutations = $this->statementNodeFinder->findAll(
            $statements,
            ConditionalContextPolicy::SkipConditionalContexts,
            fn(Node $node): bool => $this->mutatesVariable($node, $variableName),
        );

        if ($unconditionalMutations !== []) {
            return ReturnVariableResolution::refused(ReturnVariableRefusal::MutatedAfterAssignment);
        }

        // Any value-replacing rebinding beyond the single plain assignment (a `foreach` target,
        // destructuring, a reference alias, increment/decrement, a `catch` capture, or
        // `static`/`global`/`unset`) leaves the assigned value stale, whether or not it is
        // conditional. Compound assignment (`$v += …`) is excluded here: it is the additive
        // mutation the gate above owns, refused only when unconditional so a *conditional* merge
        // keeps the base value as a never-wrong subset. VariableRebinding matches the plain
        // assignment too, so a clean single-assignment method yields exactly one match; more than
        // one means an extra rebinding form is present.
        $rebindings = $this->statementNodeFinder->findAll(
            $statements,
            ConditionalContextPolicy::IncludeConditionalContexts,
            static fn(Node $node): bool
                => !$node instanceof AssignOp && VariableRebinding::matches($node, $variableName),
        );

        if (count($rebindings) > 1) {
            return ReturnVariableResolution::refused(ReturnVariableRefusal::ReboundAfterAssignment);
        }

        /** @var Assign $assignment */
        $assignment = $unconditionalAssignments[0];

        return ReturnVariableResolution::resolved($assignment->expr);
    }

    /**
     * Whether the node mutates the named variable after its assignment: an element write
     * (`$v['k'] = …`, an `Assign` whose target is an array-dimension fetch rooted at the variable)
     * or any compound assignment (`$v += …`, `$v['k'] .= …`).
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
     * Depth-first return collection, skipping nested scopes (closures, arrow functions, functions,
     * and anonymous classes). {@see FunctionLike} covers closures/arrow functions/nested functions;
     * {@see ClassLike} covers anonymous classes — the union is a superset of the skip-predicates the
     * consolidated readers used.
     *
     * @param list<Return_> $returns
     */
    private function collectReturns(Node $node, array &$returns): void
    {
        if ($node instanceof FunctionLike || $node instanceof ClassLike) {
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
