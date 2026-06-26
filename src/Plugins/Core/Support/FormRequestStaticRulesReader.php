<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use Illuminate\Container\Attributes\Scoped;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\NonLiteralValueException;
use Radiergummi\OpenApi\Support\MethodBody\RuleFieldLiteralMapper;
use Radiergummi\OpenApi\Support\MethodBody\SingleReturnArrayLiteralFinder;
use ReflectionMethod;

use function count;
use function in_array;
use function is_array;
use function is_string;

/**
 * Statically recovers a {@see \Illuminate\Foundation\Http\FormRequest}'s `rules()` array literal
 * from the method body, as a fallback for when invoking `rules()` throws on runtime state.
 *
 * Matches two whitelisted shapes in the first {@see self::STATEMENT_LIMIT} top-level statements:
 * a bare `return [ … ];` and a `$rules = [ … ]; … return $rules;` variable return. Conditional
 * `$rules[…] = …` tweaks are ignored: the base literal entries are never-wrong, and overriding
 * them would be a guess. Anything else yields null and the caller degrades as before.
 *
 * @internal
 */
#[Scoped]
final readonly class FormRequestStaticRulesReader
{
    public const int STATEMENT_LIMIT = SingleReturnArrayLiteralFinder::STATEMENT_LIMIT;

    private SingleReturnArrayLiteralFinder $bareReturnFinder;

    public function __construct(
        private MethodBodyScanner $scanner = new MethodBodyScanner(),
    ) {
        $this->bareReturnFinder = new SingleReturnArrayLiteralFinder($this->scanner);
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
     */
    private function variableReturnLiteral(ReflectionMethod $method): ?Array_
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

        if (!in_array($return, $statements, strict: true) || !$return->expr instanceof Variable) {
            return null;
        }

        $variableName = $return->expr->name;

        if (!is_string($variableName)) {
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
