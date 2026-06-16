<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations\Schema;
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
 * Policy-free reader for `response()->json(...)` calls in a controller method body.
 *
 * Extracts the literal status and body schema; degrade conditions are reported as phrases on
 * {@see InlineJsonCallResult}. Callers apply their own status-range policy.
 *
 * @internal
 */
#[Scoped]
final readonly class InlineJsonCallReader
{
    /** Argument positions in `ResponseFactory::json($data = [], $status = 200, …)`. */
    private const int DATA_ARGUMENT_POSITION = 0;

    private const int STATUS_ARGUMENT_POSITION = 1;

    /** Chained methods (lowercased) that only add headers/cookies without changing status or body. */
    private const array RESPONSE_PRESERVING_CHAIN_METHODS = [
        'header',
        'withheaders',
        'cookie',
        'withcookie',
    ];

    private StatementNodeFinder $statementNodeFinder;

    public function __construct()
    {
        $this->statementNodeFinder = new StatementNodeFinder();
    }

    // region Call-shape matching

    /** Whether the node is a `->json(...)` call on a zero-argument `response()` helper. */
    public function isJsonHelperCall(Node $node): bool
    {
        return $this->isFactoryMethodCall($node, 'json');
    }

    /** Whether the node is a `->{$method}(...)` call on a zero-argument `response()` helper. */
    public function isFactoryMethodCall(Node $node, string $method): bool
    {
        return $node instanceof MethodCall
            && !$node->isFirstClassCallable()
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === $method
            && $this->isResponseHelperCall($node->var);
    }

    /**
     * A fully-qualified `\response` always matches; an unqualified name is the global helper
     * unless a same-namespace `response()` function exists (which PHP would call instead).
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
     * @param list<Stmt> $statements the scanned top-level statements (the chain walk searches them)
     */
    public function read(array $statements, MethodCall $call): InlineJsonCallResult
    {
        $arguments = $call->getArgs();

        if (array_any($arguments, fn(Arg|Node\VariadicPlaceholder $argument): bool => $argument->unpack)) {
            return new InlineJsonCallResult(
                status: null,
                statusDegradeReason: 'spreads its arguments, so they cannot be read statically',
            );
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
     * Walks the chain outwards from `json()`: passes through header/cookie links, returns an int
     * for `->setStatusCode(<literal>)`, degrades on any other link.
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
     * Returns the method call whose receiver is exactly `$call`, or null if not chained further.
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

    /** Literal integer status from a `->setStatusCode()` call, or null if absent or unreadable. */
    private function literalStatusArgument(MethodCall $call): ?int
    {
        $statusArgument = $call->getArgs()[0] ?? null;

        if ($statusArgument === null || $statusArgument->unpack) {
            return null;
        }

        $status = $this->literalValueOf($statusArgument->value);

        return is_int($status) ? $status : null;
    }

    private function literalValueOf(Expr $expression): mixed
    {
        try {
            return AstLiteralEvaluator::evaluate($expression);
        } catch (NonLiteralValueException) {
            return null;
        }
    }

    /**
     * @param array<int, Arg> $arguments
     */
    private function argument(array $arguments, string $name, int $position): ?Arg
    {
        return array_find(
            $arguments,
            fn(Arg $argument, int $index): bool
                => $argument->name === null ? $index === $position : $argument->name->toString() === $name,
        );
    }

    /**
     * @param array<int, Arg> $arguments
     *
     * @return array{0: ?Schema, 1: bool, 2: ?string}
     */
    private function readBody(array $arguments): array
    {
        $dataArgument = $this->argument($arguments, 'data', self::DATA_ARGUMENT_POSITION);

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

        // A literal scalar is a valid JSON document; null and empty array carry no shape.
        $definition = SchemaDefinitionFromLiteral::fromLiteralValue($literal);

        return $definition === []
            ? [null, true, null]
            : [SchemaFromArrayDefinition::build($definition), true, null];
    }

    // endregion
}
