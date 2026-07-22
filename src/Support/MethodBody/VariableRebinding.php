<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Static_;
use PhpParser\Node\Stmt\Unset_;

/**
 * Whether an AST node binds a new value to a named local, invalidating whatever a scanner knew
 * about it.
 *
 * The whole PHP surface that writes a name, not only `$x = …`: destructuring, reference aliasing,
 * compound assignment, increment/decrement, `foreach` targets, `catch` captures, and the
 * `static`/`global`/`unset` statements. A predicate that matches less silently keeps a stale
 * narrowing alive across the statement that voided it.
 *
 * Intended for {@see StatementNodeFinder::findAll()} under
 * {@see ConditionalContextPolicy::IncludeConditionalContexts}: a rebinding inside an `if` body
 * still invalidates the name.
 *
 * @internal
 */
final class VariableRebinding
{
    public static function matches(Node $node, string $variableName): bool
    {
        if ($node instanceof Assign || $node instanceof AssignRef) {
            // `$other = &$subject;` aliases the name, so later writes through `$other` reach it.
            return self::bindsName($node->var, $variableName)
                || ($node instanceof AssignRef && self::isNamed($node->expr, $variableName));
        }

        if (
            $node instanceof AssignOp
            || $node instanceof PreInc
            || $node instanceof PreDec
            || $node instanceof PostInc
            || $node instanceof PostDec
        ) {
            return self::isNamed($node->var, $variableName);
        }

        if ($node instanceof Foreach_) {
            return self::bindsName($node->valueVar, $variableName)
                || ($node->keyVar !== null && self::bindsName($node->keyVar, $variableName));
        }

        if ($node instanceof Catch_) {
            return $node->var !== null && self::isNamed($node->var, $variableName);
        }

        if ($node instanceof Static_) {
            foreach ($node->vars as $staticVariable) {
                if (self::isNamed($staticVariable->var, $variableName)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof Global_ || $node instanceof Unset_) {
            foreach ($node->vars as $variable) {
                if (self::isNamed($variable, $variableName)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * Whether an assignment target writes the name, directly or through a destructuring pattern
     * (`[$a, $subject] = …`, `list($subject) = …`, `['k' => $subject] = …`).
     */
    private static function bindsName(Expr $target, string $variableName): bool
    {
        if ($target instanceof Array_ || $target instanceof List_) {
            foreach ($target->items as $item) {
                if ($item !== null && self::bindsName($item->value, $variableName)) {
                    return true;
                }
            }

            return false;
        }

        return self::isNamed($target, $variableName);
    }

    private static function isNamed(Expr $expression, string $variableName): bool
    {
        return $expression instanceof Variable && $expression->name === $variableName;
    }
}
