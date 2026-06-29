<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData\Support;

use Illuminate\Container\Attributes\Scoped;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure as ClosureExpression;
use PhpParser\Node\Expr\New_ as NewExpression;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Return_;
use Radiergummi\OpenApi\Support\MethodBody\ConditionalContextPolicy;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\StatementNodeFinder;
use ReflectionMethod;
use Spatie\LaravelData\Data;

use function array_key_exists;
use function class_exists;
use function count;
use function is_a;
use function is_array;
use function is_string;

/**
 * Recovers the concrete Spatie {@see Data} class an action returns when its signature names only a
 * generic container (`Collection`, `array`, …). Consulted by `DataResponseResolver` as a fallback.
 *
 * Bounded scan of the first {@see self::STATEMENT_LIMIT} statements: one unconditional return (or
 * the variable it names, assigned exactly once on the unconditional path), matched against two
 * literal-class shapes — `DataClass::collect(...)` (collection) and `new DataClass(...)` (single).
 * Anything else, or a non-Data class, reads back as null and degrades silently. Memoised per method.
 *
 * @internal
 */
#[Scoped]
final class DataReturnExpressionReader
{
    public const int STATEMENT_LIMIT = 10;

    /**
     * Memoised per `Class::method`.
     *
     * @var array<string, ?DataReturnTarget>
     */
    private array $cache = [];

    private readonly StatementNodeFinder $statementNodeFinder;

    public function __construct(private readonly MethodBodyScanner $scanner)
    {
        $this->statementNodeFinder = new StatementNodeFinder();
    }

    public static function create(): self
    {
        return new self(new MethodBodyScanner());
    }

    public function read(ReflectionMethod $method): ?DataReturnTarget
    {
        $key = $method->getDeclaringClass()->getName() . '::' . $method->getName();

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->resolve($method);
    }

    private function resolve(ReflectionMethod $method): ?DataReturnTarget
    {
        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);
        $returnExpression = $this->canonicalReturnExpression($statements);

        if ($returnExpression === null) {
            return null;
        }

        $expression = $returnExpression instanceof Variable
            ? $this->expressionAssignedTo($returnExpression, $statements)
            : $returnExpression;

        if ($expression === null) {
            return null;
        }

        return $this->targetFromExpression($expression);
    }

    /**
     * The single unconditional top-level return expression, or null when absent or when the method
     * has more than one return (the Data type would be a guess).
     *
     * @param list<Stmt> $statements
     */
    private function canonicalReturnExpression(array $statements): ?Expr
    {
        $topLevelReturn = null;

        foreach ($statements as $statement) {
            if ($statement instanceof Return_) {
                $topLevelReturn = $statement;

                break;
            }
        }

        if ($topLevelReturn === null || $topLevelReturn->expr === null) {
            return null;
        }

        $found = [];

        foreach ($statements as $statement) {
            $this->collectMethodLevelReturns($statement, $found);
        }

        if (count($found) > 1) {
            return null;
        }

        return $topLevelReturn->expr;
    }

    /**
     * Returns belonging to the method itself; closures, arrow functions, and anonymous classes
     * open their own scope and are excluded.
     *
     * @param list<Return_> $found
     */
    private function collectMethodLevelReturns(Node $node, array &$found): void
    {
        if (
            $node instanceof ClosureExpression
            || $node instanceof ArrowFunction
            || $node instanceof ClassLike
        ) {
            return;
        }

        if ($node instanceof Return_) {
            $found[] = $node;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            /** @var mixed $children */
            $children = $node->{$subNodeName};

            foreach (is_array($children) ? $children : [$children] as $child) {
                if ($child instanceof Node) {
                    $this->collectMethodLevelReturns($child, $found);
                }
            }
        }
    }

    /**
     * Resolves `return $variable;` through the single unconditional assignment to that variable.
     * A conditional reassignment makes the type a guess.
     *
     * @param list<Stmt> $statements
     */
    private function expressionAssignedTo(Variable $variable, array $statements): ?Expr
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

        /** @var Assign $assignment */
        $assignment = $unconditionalAssignments[0];

        return $assignment->expr;
    }

    /**
     * `DataClass::collect(...)` → collection of `DataClass`; `new DataClass(...)` → a single one.
     */
    private function targetFromExpression(Expr $expression): ?DataReturnTarget
    {
        if (
            $expression instanceof StaticCall
            && $expression->class instanceof Name
            && $expression->name instanceof Identifier
            && $expression->name->toLowerString() === 'collect'
        ) {
            return $this->target($expression->class->toString(), isCollection: true);
        }

        if ($expression instanceof NewExpression && $expression->class instanceof Name) {
            return $this->target($expression->class->toString(), isCollection: false);
        }

        return null;
    }

    private function target(string $class, bool $isCollection): ?DataReturnTarget
    {
        if (!class_exists($class) || !is_a($class, Data::class, allow_string: true)) {
            return null;
        }

        /** @var class-string<Data> $class */
        return new DataReturnTarget($class, $isCollection);
    }
}
