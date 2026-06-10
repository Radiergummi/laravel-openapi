<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

/**
 * Governs whether {@see StatementNodeFinder} descends into conditional contexts.
 *
 * Each Tier-1 consumer picks the policy that matches its question: a request-body matcher must
 * refuse a branch-dependent `validate()` (a conditional rules array is not *the* request body),
 * while an error-response matcher wants exactly the `abort(403)` guarded by an `if`.
 *
 * @internal
 */
enum ConditionalContextPolicy
{
    /**
     * Straight-line statements only: expression statements, plain assignments, and returns.
     * Closure and arrow-function bodies, ternary and null-coalescing expressions, `&&` / `||`
     * short-circuits, `match` expressions, and any conditional statement (`if`, loops, …) are
     * not entered — a node found under this policy executes unconditionally.
     */
    case SkipConditionalContexts;

    /**
     * Full descent, including conditional statements and expressions and closure bodies.
     */
    case IncludeConditionalContexts;
}
