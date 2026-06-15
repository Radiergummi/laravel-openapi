<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use Illuminate\Container\Attributes\Scoped;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use Radiergummi\OpenApi\Support\Generator\SchemaFromArrayDefinition;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\ConditionalContextPolicy;
use Radiergummi\OpenApi\Support\MethodBody\NonLiteralValueException;
use Radiergummi\OpenApi\Support\MethodBody\SchemaDefinitionFromLiteral;
use Radiergummi\OpenApi\Support\MethodBody\StatementNodeFinder;

use function array_find;
use function function_exists;
use function in_array;
use function is_int;
use function sprintf;
use function strtolower;

/**
 * Shared, policy-free reader for `response()->json(...)` calls in a controller method body —
 * the recognition and literal-reading half of the Tier-1 inline-json scan (epic #5, issues
 * #14 / #238).
 *
 * Two callers consume it with opposite status policies: {@see InlineJsonResponseResolver} claims
 * only 2xx literals for the primary response slot, while {@see InlineJsonErrorContributor} routes
 * only 4xx/5xx literals into the error machinery. This reader reports the *facts* — the literal
 * status (the `json()` status argument or a chained `->setStatusCode()` override) and the literal
 * body schema — and the callers apply their own 2xx/non-2xx filter. It owns no logger: degrade
 * conditions are reported as human phrases on {@see InlineJsonCallResult} for the caller to note.
 *
 * @internal
 */
#[Scoped]
final readonly class InlineJsonCallReader
{
    /**
     * Positions in Laravel's `ResponseFactory::json($data = [], $status = 200, …)` signature,
     * used to resolve arguments by position or by name.
     */
    private const int DATA_ARGUMENT_POSITION = 0;

    private const int STATUS_ARGUMENT_POSITION = 1;

    /**
     * Chained methods (lowercased) that cannot change the response's status or body — header and
     * cookie decoration only. A `->setStatusCode(<literal>)` link is read as a status override;
     * any other chained call (`setData`, `setNotModified`, …) may invalidate what the matched
     * call promised, so it degrades the read.
     */
    private const array RESPONSE_PRESERVING_CHAIN_METHODS = ['header', 'withheaders', 'cookie', 'withcookie'];

    private StatementNodeFinder $statementNodeFinder;

    public function __construct()
    {
        $this->statementNodeFinder = new StatementNodeFinder();
    }

    // region Call-shape matching

    /**
     * Whether the node is a `->{$method}(...)` call (method name lowercased) on a zero-argument
     * `response()` helper call.
     */
    public function isFactoryMethodCall(Node $node, string $method): bool
    {
        return $node instanceof MethodCall
            && !$node->isFirstClassCallable()
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === $method
            && $this->isResponseHelperCall($node->var);
    }

    /**
     * Whether the node is a `->json(...)` method call on a zero-argument `response()` helper call.
     */
    public function isJsonHelperCall(Node $node): bool
    {
        return $this->isFactoryMethodCall($node, 'json');
    }

    /**
     * Names arrive resolved by the scanner's NameResolver pass. A fully-qualified name matches
     * when it is the root-namespace helper itself (`\response`); an *unqualified* name in a
     * namespaced file stays unresolved (PHP's runtime fallback), so it matches as Laravel's
     * global helper — unless a same-namespace function of that name is actually defined, in
     * which case PHP would call the user's function and we must not document it.
     */
    public function isResponseHelperCall(Expr $receiver): bool
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

        if ($receiver->name->isFullyQualified()) {
            return true;
        }

        $namespacedName = $receiver->name->getAttribute('namespacedName');

        return !($namespacedName instanceof Name && function_exists($namespacedName->toString()));
    }

    // endregion

    // region Per-call reading

    /**
     * Reads one matched `response()->json(...)` call into the facts a caller needs: its literal
     * status (the `json()` status argument, 200 when absent, or a chained `->setStatusCode()`
     * override) and its literal body schema. Applies no 2xx/non-2xx policy and emits no log; a
     * non-readable status or body is reported as a degrade phrase on the result.
     *
     * @param list<Stmt> $statements the scanned top-level statements (the chain walk searches them)
     */
    public function read(array $statements, MethodCall $call): InlineJsonCallResult
    {
        $arguments = $call->getArgs();

        foreach ($arguments as $argument) {
            if ($argument->unpack) {
                return new InlineJsonCallResult(
                    status: null,
                    statusDegradeReason: 'spreads its arguments, so they cannot be read statically',
                );
            }
        }

        $status = $this->resolveStatus($statements, $call, $arguments);

        if ($status instanceof InlineJsonCallResult) {
            return $status;
        }

        [$bodySchema, $bodyReadable, $bodyDegradeReason] = $this->readBody($arguments);

        return new InlineJsonCallResult(
            status: $status,
            bodySchema: $bodySchema,
            bodyReadable: $bodyReadable,
            bodyDegradeReason: $bodyDegradeReason,
        );
    }

    /**
     * The call's literal status: a chained `->setStatusCode(<literal>)` override wins, otherwise
     * the `json()` status argument (200 when absent). Returns an int, or an already-degraded
     * {@see InlineJsonCallResult} when the status (or a body-mutating chain) is not statically
     * readable.
     *
     * @param list<Stmt>      $statements
     * @param array<int, Arg> $arguments
     */
    private function resolveStatus(array $statements, MethodCall $call, array $arguments): int|InlineJsonCallResult
    {
        $chainStatus = $this->statusFromChain($statements, $call);

        if ($chainStatus instanceof InlineJsonCallResult) {
            return $chainStatus;
        }

        if ($chainStatus !== null) {
            return $chainStatus;
        }

        $statusArgument = $this->argument($arguments, 'status', self::STATUS_ARGUMENT_POSITION);

        if ($statusArgument === null) {
            return 200;
        }

        $status = $this->literalValueOf($statusArgument->value);

        if (!is_int($status)) {
            return new InlineJsonCallResult(
                status: null,
                statusDegradeReason: 'has no statically readable status code, so the body must not '
                    . 'be documented under a guessed status',
            );
        }

        return $status;
    }

    /**
     * Walks the chain outwards from the matched `json()` to determine a status override. Header
     * and cookie links leave the status untouched (null); a `->setStatusCode(<literal>)` overrides
     * it (int); any other link — a non-literal `->setStatusCode()` or a body-mutating `->setData()`
     * — degrades the read (an {@see InlineJsonCallResult} carrying the reason).
     *
     * @param list<Stmt> $statements
     */
    private function statusFromChain(array $statements, MethodCall $call): int|InlineJsonCallResult|null
    {
        $current = $call;
        $statusOverride = null;

        while (($parent = $this->chainParentOf($statements, $current)) !== null) {
            $name = $parent->name instanceof Identifier ? strtolower($parent->name->toString()) : null;

            if ($name === 'setstatuscode') {
                $override = $this->literalStatusArgument($parent);

                if ($override === null) {
                    return new InlineJsonCallResult(
                        status: null,
                        statusDegradeReason: 'is chained into ->setStatusCode() with no statically '
                            . 'readable status code, so the body must not be documented under a guessed status',
                    );
                }

                $statusOverride = $override;
                $current = $parent;

                continue;
            }

            if ($name === null || !in_array($name, self::RESPONSE_PRESERVING_CHAIN_METHODS, true)) {
                return new InlineJsonCallResult(
                    status: null,
                    statusDegradeReason: sprintf(
                        'is chained into ->%s(), which may change the response status or body',
                        $parent->name instanceof Identifier ? $parent->name->toString() : '{dynamic}',
                    ),
                );
            }

            $current = $parent;
        }

        return $statusOverride;
    }

    /**
     * The literal integer argument of a `->setStatusCode(<literal|class-constant>)` link, or null
     * when absent, unpacked, or not statically readable.
     */
    private function literalStatusArgument(MethodCall $call): ?int
    {
        $statusArgument = $call->getArgs()[0] ?? null;

        if ($statusArgument === null || $statusArgument->unpack) {
            return null;
        }

        $status = $this->literalValueOf($statusArgument->value);

        return is_int($status) ? $status : null;
    }

    /**
     * The literal body schema for the call's `data` argument plus its readability flag and degrade
     * phrase: a literal array/scalar becomes a schema; an empty literal or absent argument carries
     * no shape (null schema, readable); a non-literal expression is reported unreadable.
     *
     * @param array<int, Arg> $arguments
     *
     * @return array{0: ?\OpenApi\Annotations\Schema, 1: bool, 2: ?string}
     */
    private function readBody(array $arguments): array
    {
        $dataArgument = $this->argument($arguments, 'data', self::DATA_ARGUMENT_POSITION);

        // No data argument means an empty `[]` body — readable, but carrying no shape.
        if ($dataArgument === null) {
            return [null, true, null];
        }

        $data = $dataArgument->value;

        if ($data instanceof Array_) {
            if ($data->items === []) {
                return [null, true, null];
            }

            try {
                $definition = SchemaDefinitionFromLiteral::fromArrayNode($data);
            } catch (NonLiteralValueException) {
                return [
                    null,
                    false,
                    'has an array literal whose structure (a key or spread entry) is not statically readable',
                ];
            }

            return [SchemaFromArrayDefinition::build($definition), true, null];
        }

        try {
            $literal = AstLiteralEvaluator::evaluate($data);
        } catch (NonLiteralValueException) {
            return [null, false, 'has no statically readable body'];
        }

        // A literal scalar (`json('ok')`, `json(true)`) is a valid JSON document; `null` and an
        // empty array carry no shape.
        $definition = SchemaDefinitionFromLiteral::fromLiteralValue($literal);

        return $definition === []
            ? [null, true, null]
            : [SchemaFromArrayDefinition::build($definition), true, null];
    }

    /**
     * The method call whose receiver is exactly the given call (the next link outwards in a
     * fluent chain), or null when the call is not chained into another method call.
     *
     * @param list<Stmt> $statements
     */
    private function chainParentOf(array $statements, MethodCall $call): ?MethodCall
    {
        $parent = $this->statementNodeFinder->findFirst(
            $statements,
            ConditionalContextPolicy::IncludeConditionalContexts,
            static fn(Node $node): bool => $node instanceof MethodCall && $node->var === $call,
        );

        return $parent instanceof MethodCall ? $parent : null;
    }

    /**
     * Resolves an argument by position (unnamed) or by name, mirroring how PHP binds the call.
     *
     * @param array<int, Arg> $arguments
     */
    private function argument(array $arguments, string $name, int $position): ?Arg
    {
        return array_find(
            $arguments,
            fn(
                $argument,
                $index,
            ) => $argument->name === null ? $index === $position : $argument->name->toString() === $name,
        );
    }

    private function literalValueOf(Expr $expression): mixed
    {
        try {
            return AstLiteralEvaluator::evaluate($expression);
        } catch (NonLiteralValueException) {
            return null;
        }
    }

    // endregion
}
