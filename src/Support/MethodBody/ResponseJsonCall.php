<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

/**
 * Matches a `response()->json(<data>, <status>)` helper call in a scanned method body and exposes
 * its data argument. Covers the `response()` helper form only; `new JsonResponse(...)` is a
 * distinct construction handled by its callers. Relies on the scanner's NameResolver so the
 * helper-shadowing guard ({@see UnqualifiedHelperCall}) can distinguish the global helper from a
 * same-namespace `response()` function.
 *
 * @internal
 */
final readonly class ResponseJsonCall
{
    /** Argument position of the JSON data in `ResponseFactory::json($data = [], $status = 200, …)`. */
    private const int DATA_ARGUMENT_POSITION = 0;

    /** Whether the node is a `->json(...)` call on a zero-argument `response()` helper. */
    public static function matches(Node $node): bool
    {
        return $node instanceof MethodCall
            && !$node->isFirstClassCallable()
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === 'json'
            && self::isResponseHelperReceiver($node->var);
    }

    /**
     * The data (`data`/first) argument of a matched `response()->json(...)` call, or null when the
     * node is not that shape or the argument is absent.
     */
    public static function dataArgument(Node $node): ?Arg
    {
        if (!$node instanceof MethodCall || !self::matches($node)) {
            return null;
        }

        return CallArgumentResolver::argument($node->getArgs(), 'data', self::DATA_ARGUMENT_POSITION);
    }

    /**
     * Whether the expression is the zero-argument `response()` helper. A fully-qualified `\response`
     * always matches; an unqualified name is the global helper unless a same-namespace `response()`
     * function exists (which PHP would call instead).
     */
    public static function isResponseHelperReceiver(Expr $receiver): bool
    {
        if (
            !$receiver instanceof FuncCall
            || $receiver->isFirstClassCallable()
            || !$receiver->name instanceof Name
            || $receiver->name->toLowerString() !== 'response'
            || $receiver->getArgs() !== []
        ) {
            return false;
        }

        return UnqualifiedHelperCall::resolvesToGlobalHelper($receiver->name);
    }
}
