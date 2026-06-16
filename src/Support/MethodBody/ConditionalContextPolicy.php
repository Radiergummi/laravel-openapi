<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

/**
 * Governs whether {@see StatementNodeFinder} descends into conditional contexts.
 *
 * A request-body matcher should refuse a branch-dependent `validate()` (a conditional rules
 * array is not *the* request body), while an error-response matcher needs the `abort(403)`
 * guarded by an `if`.
 *
 * @internal
 */
enum ConditionalContextPolicy
{
    /**
     * Straight-line code only: assignments, expression statements, returns.
     * Conditionals, loops, closures, match, ternary, and `&&`/`||` are not entered.
     * A node found here executes unconditionally.
     */
    case SkipConditionalContexts;

    /**
     * Full descent, including conditionals, loops, and closure bodies.
     */
    case IncludeConditionalContexts;
}
