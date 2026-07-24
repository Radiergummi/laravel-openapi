<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData\Support;

use Illuminate\Container\Attributes\Scoped;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\New_ as NewExpression;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Return_;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\ReturnExpressionResolver;
use Radiergummi\OpenApi\Support\MethodBody\ReturnScan;
use ReflectionMethod;
use Spatie\LaravelData\Data;

use function array_key_exists;
use function class_exists;
use function count;
use function is_a;

/**
 * Recovers the concrete Spatie {@see Data} class an action returns when its signature names only a
 * generic container (`Collection`, `array`, …). Consulted by `DataResponseResolver` as a fallback.
 *
 * Bounded scan of the method's statements (capped by {@see ReturnScan::STATEMENT_LIMIT}):
 * one unconditional return (or the variable it names, assigned exactly once on the unconditional
 * path and never mutated after), matched against two literal-class shapes:
 * `DataClass::collect(...)` (collection) and `new DataClass(...)` (single). Anything else, or a
 * non-Data class, reads back as null and degrades silently. Memoised per method.
 *
 * @internal
 */
#[Scoped]
final class DataReturnExpressionReader
{
    /**
     * Memoised per `Class::method`.
     *
     * @var array<string, ?DataReturnTarget>
     */
    private array $cache = [];

    public function __construct(
        private readonly MethodBodyScanner $scanner,
        private readonly ReturnExpressionResolver $returnExpressionResolver = new ReturnExpressionResolver(),
    ) {}

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
        $statements = $this->scanner->firstStatements($method, ReturnScan::STATEMENT_LIMIT);
        $returnExpression = $this->canonicalReturnExpression($statements);

        if ($returnExpression === null) {
            return null;
        }

        $expression = $returnExpression instanceof Variable
            ? $this->returnExpressionResolver->resolveVariable($returnExpression, $statements)->expression
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

        if (count($this->returnExpressionResolver->methodLevelReturns($statements)) > 1) {
            return null;
        }

        return $topLevelReturn->expr;
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
