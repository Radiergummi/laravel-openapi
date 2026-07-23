<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use Illuminate\Container\Attributes\Scoped;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Expression;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\ConditionalContextPolicy;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\NonLiteralValueException;
use Radiergummi\OpenApi\Support\MethodBody\ReturnExpressionResolver;
use Radiergummi\OpenApi\Support\MethodBody\RuleFieldLiteralMapper;
use Radiergummi\OpenApi\Support\MethodBody\SingleReturnArrayLiteralFinder;
use Radiergummi\OpenApi\Support\MethodBody\StatementNodeFinder;
use Radiergummi\OpenApi\Support\MethodBody\VariableRebinding;
use ReflectionMethod;

use function count;
use function in_array;
use function is_string;

/**
 * Statically recovers a {@see \Illuminate\Foundation\Http\FormRequest}'s `rules()` array literal
 * from the method body, as a fallback for when invoking `rules()` throws on runtime state.
 *
 * Matches two whitelisted shapes, scanning the method up to a
 * {@see self::RETURN_SCAN_STATEMENT_LIMIT}-statement backstop for its single return: a bare
 * `return [ … ];` and a `$rules = [ … ]; … return $rules;` variable return. Conditional
 * `$rules[…] = …` tweaks are ignored: the base literal entries are never-wrong, and overriding
 * them would be a guess. A value-replacing rebinding of the returned variable (a reassignment,
 * `foreach` target, destructuring, or reference alias) leaves the base literal stale, so it is
 * refused. Anything else yields null and the caller degrades as before.
 *
 * @internal
 */
#[Scoped]
final readonly class FormRequestStaticRulesReader
{
    public const int RETURN_SCAN_STATEMENT_LIMIT = SingleReturnArrayLiteralFinder::RETURN_SCAN_STATEMENT_LIMIT;

    private SingleReturnArrayLiteralFinder $bareReturnFinder;

    private StatementNodeFinder $statementNodeFinder;

    public function __construct(
        private MethodBodyScanner $scanner,
        private ReturnExpressionResolver $returnExpressionResolver = new ReturnExpressionResolver(),
    ) {
        $this->bareReturnFinder = new SingleReturnArrayLiteralFinder(
            $this->scanner,
            $this->returnExpressionResolver,
        );
        $this->statementNodeFinder = new StatementNodeFinder();
    }

    /**
     * @return null|array<string, array<int, mixed>|string>
     */
    public function read(ReflectionMethod $rulesMethod): ?array
    {
        $literal = $this->bareReturnFinder->find($rulesMethod)
            ?? $this->variableReturnLiteral($rulesMethod);

        if ($literal === null) {
            return null;
        }

        return $this->rulesFromArrayLiteral($literal);
    }

    /**
     * Resolves a `$rules = [ … ]; … return $rules;` body to its base array literal. Requires a
     * single, top-level `return $variable;` (the same guard the bare-return finder applies) and the
     * first top-level `$variable = [ … ];` assignment; later `$variable[…] = …` tweaks are ignored.
     * A value-replacing rebinding of the variable (a reassignment, `foreach` target, destructuring,
     * or reference alias) is refused, so the base literal is never reported when it has gone stale.
     */
    private function variableReturnLiteral(ReflectionMethod $method): ?Array_
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

        if (!in_array($return, $statements, strict: true) || !$return->expr instanceof Variable) {
            return null;
        }

        $variableName = $return->expr->name;

        if (!is_string($variableName)) {
            return null;
        }

        // This fallback path does not go through ReturnExpressionResolver::resolveVariable(), so it
        // repeats that resolver's rebinding guard: more than one value-replacing write (the base
        // assignment plus any reassignment, `foreach` target, destructuring, or reference alias)
        // means the base literal no longer describes the returned value. Compound assignment is
        // excluded, matching the resolver, so an additive `$rules[…] = …` keeps the never-wrong base.
        $rebindings = $this->statementNodeFinder->findAll(
            $statements,
            ConditionalContextPolicy::IncludeConditionalContexts,
            static fn(Node $node): bool
                => !$node instanceof AssignOp && VariableRebinding::matches($node, $variableName),
        );

        if (count($rebindings) > 1) {
            return null;
        }

        foreach ($statements as $statement) {
            if (!$statement instanceof Expression || !$statement->expr instanceof Assign) {
                continue;
            }

            $assignment = $statement->expr;

            if (
                $assignment->var instanceof Variable
                && $assignment->var->name === $variableName
                && $assignment->expr instanceof Array_
            ) {
                return $assignment->expr;
            }
        }

        return null;
    }

    /**
     * Maps a `rules` array literal to the raw rule shape, recovering what it can: an entry whose
     * key or value is not a readable literal is skipped, the literal rest is kept. An all-anomalous
     * (or empty) literal yields null.
     *
     * @return null|array<string, array<int, mixed>|string>
     */
    private function rulesFromArrayLiteral(Array_ $literal): ?array
    {
        /** @var array<string, array<int, mixed>|string> $rules */
        $rules = [];

        foreach ($literal->items as $item) {
            if ($item->unpack || $item->key === null) {
                continue;
            }

            try {
                $fieldName = AstLiteralEvaluator::evaluate($item->key);
            } catch (NonLiteralValueException) {
                continue;
            }

            if (!is_string($fieldName)) {
                continue;
            }

            $fieldRules = RuleFieldLiteralMapper::map($item->value);

            if ($fieldRules === null) {
                continue;
            }

            $rules[$fieldName] = $fieldRules;
        }

        return $rules === [] ? null : $rules;
    }
}
