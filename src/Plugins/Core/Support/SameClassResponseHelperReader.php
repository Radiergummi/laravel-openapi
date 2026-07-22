<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use Illuminate\Container\Attributes\Scoped;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\CallArgumentResolver;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\NonLiteralValueException;
use Radiergummi\OpenApi\Support\MethodBody\ReturnExpressionResolver;
use ReflectionException;
use ReflectionMethod;

use function count;
use function in_array;
use function is_int;
use function method_exists;
use function str_contains;
use function strtolower;

/**
 * Reads a same-class controller response helper (`$this->empty()`) for the status it documents, one
 * hop from the call site with no recursion and no dataflow.
 *
 * The read is intentionally narrow: it accepts a status only for a **directly-returned body-less
 * construction** in the helper's own body. A construction reached through a variable, a delegation
 * to another same-class method, or a body-bearing construction all refuse — the reader never trusts
 * the single-assignment trace to prove body-safety, because that trace cannot see a later
 * `->setData()` mutation. It derives a *status* only; it never reads a body.
 *
 * @internal
 */
#[Scoped]
final readonly class SameClassResponseHelperReader
{
    /** Statement window mirroring {@see InlineJsonResponseResolver::STATEMENT_LIMIT}. */
    private const int STATEMENT_LIMIT = 10;

    /** Chained methods (lowercased) that only add headers/cookies, preserving status and body. */
    private const array PRESERVING_CHAIN_METHODS = [
        'header',
        'withheaders',
        'cookie',
        'withcookie',
    ];

    private ReturnExpressionResolver $returnExpressionResolver;

    public function __construct(
        private MethodBodyScanner $scanner,
        private InlineJsonCallReader $callReader,
    ) {
        $this->returnExpressionResolver = new ReturnExpressionResolver();
    }

    /**
     * Reads the helper `$callerClass::$methodName` for its documented status, given the arguments
     * the call site passed.
     *
     * @param class-string    $callerClass   the class the call resolves `$this->` against
     * @param array<int, Arg> $callArguments the `$this->helper(...)` arguments at the call site
     */
    public function read(string $callerClass, string $methodName, array $callArguments): SameClassHelperResult
    {
        if (!method_exists($callerClass, $methodName)) {
            return SameClassHelperResult::skip();
        }

        try {
            $method = new ReflectionMethod($callerClass, $methodName);
        } catch (ReflectionException) {
            return SameClassHelperResult::skip();
        }

        $file = $method->getDeclaringClass()->getFileName();

        // A vendor (or unresolvable) helper is not app code we should read a convention from.
        if ($file === false || str_contains($file, '/vendor/')) {
            return SameClassHelperResult::skip();
        }

        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);
        $returns = $this->returnExpressionResolver->methodLevelReturns($statements);

        // A single unconditional return is the only shape we read; branching helpers are ambiguous.
        if (count($returns) !== 1 || $returns[0]->expr === null) {
            return SameClassHelperResult::skip();
        }

        [$core, $bodyMutatingChain] = $this->unwrapPreservingChain($returns[0]->expr);

        if ($bodyMutatingChain) {
            return SameClassHelperResult::refused(
                'is chained into a method that may add a response body, so it is not body-less',
            );
        }

        // The direct-return gate: a construction reached through a variable cannot be proven
        // body-less (a later ->setData() would be invisible), and a delegation is a hop we refuse.
        if ($core instanceof Variable) {
            return SameClassHelperResult::refused(
                'assigns its response to a variable before returning it, so it cannot be read as body-less',
            );
        }

        if ($core instanceof MethodCall && $this->isThisCall($core)) {
            return SameClassHelperResult::refused(
                'delegates to another method, which is not read (see the same-class delegating hop follow-up)',
            );
        }

        if (!$core instanceof MethodCall && !$core instanceof New_) {
            return SameClassHelperResult::skip();
        }

        $construction = $this->classifyConstruction($core);

        if ($construction === null) {
            return SameClassHelperResult::skip();
        }

        if (!$this->isBodyLess($core, $construction)) {
            return SameClassHelperResult::skip();
        }

        $status = $this->resolveStatus($core, $construction, $method, $callArguments, $callerClass);

        if ($status === false) {
            return SameClassHelperResult::refused(
                'has no statically readable status code, so its response must not be documented under a guess',
            );
        }

        return SameClassHelperResult::resolved($status);
    }

    /**
     * Peels trailing header/cookie chain links off an expression down to its core, flagging any
     * trailing link that is not on the preserving whitelist (it may add a body).
     *
     * @return array{0: Expr, 1: bool} the core expression and whether a body-mutating link was seen
     */
    public static function unwrapPreservingChain(Expr $expression): array
    {
        $current = $expression;
        $bodyMutatingChain = false;

        // Descend through chain links (a `->foo()` whose receiver is itself a call or construction)
        // down to the base call/construction; the base's receiver is `response()` or `$this`.
        while (
            $current instanceof MethodCall
            && ($current->var instanceof MethodCall || $current->var instanceof New_)
        ) {
            $name = $current->name instanceof Identifier ? strtolower($current->name->toString()) : null;

            if ($name === null || !in_array($name, self::PRESERVING_CHAIN_METHODS, true)) {
                $bodyMutatingChain = true;
            }

            $current = $current->var;
        }

        return [$current, $bodyMutatingChain];
    }

    private function isThisCall(MethodCall $call): bool
    {
        return !$call->isFirstClassCallable()
            && $call->name instanceof Identifier
            && $call->var instanceof Variable
            && $call->var->name === 'this';
    }

    /**
     * Classifies the core as one of the recognised body-less-capable constructions, returning the
     * argument positions its body and status live at, or null when unrecognised.
     *
     * @return null|array{bodyArgumentName: ?string, bodyArgumentPosition: ?int, statusArgumentPosition: int}
     */
    private function classifyConstruction(MethodCall|New_ $core): ?array
    {
        // response()->noContent(<status>) — never carries a body; status is the first argument.
        if ($this->callReader->isFactoryMethodCall($core, 'nocontent')) {
            return ['bodyArgumentName' => null, 'bodyArgumentPosition' => null, 'statusArgumentPosition' => 0];
        }

        // response()->make($content = '', $status = 200) — content is the first argument.
        if ($this->callReader->isFactoryMethodCall($core, 'make')) {
            return ['bodyArgumentName' => 'content', 'bodyArgumentPosition' => 0, 'statusArgumentPosition' => 1];
        }

        // new JsonResponse($data = null, $status = 200) — data is the first argument.
        if ($this->callReader->isJsonResponseConstruction($core)) {
            return ['bodyArgumentName' => 'data', 'bodyArgumentPosition' => 0, 'statusArgumentPosition' => 1];
        }

        // new Response($content = '', $status = 200) — content is the first argument.
        if ($core instanceof New_ && $this->callReader->isResponseConstruction($core)) {
            return ['bodyArgumentName' => 'content', 'bodyArgumentPosition' => 0, 'statusArgumentPosition' => 1];
        }

        return null;
    }

    /**
     * Whether the construction carries no body: a `noContent()` never does; the others are body-less
     * only when their body argument is absent or a provably-empty literal (`''`, `[]`, `null`).
     *
     * @param array{bodyArgumentName: ?string, bodyArgumentPosition: ?int, statusArgumentPosition: int} $construction
     */
    private function isBodyLess(MethodCall|New_ $core, array $construction): bool
    {
        if ($construction['bodyArgumentName'] === null || $construction['bodyArgumentPosition'] === null) {
            return true;
        }

        $bodyArgument = CallArgumentResolver::argument(
            $core->getArgs(),
            $construction['bodyArgumentName'],
            $construction['bodyArgumentPosition'],
        );

        if ($bodyArgument === null || $bodyArgument->unpack) {
            return $bodyArgument === null;
        }

        // An array literal is body-less only when empty; a populated one carries a body even when
        // its values are non-literal (`['data' => $item]`), so it must not read as empty.
        if ($bodyArgument->value instanceof Array_) {
            return $bodyArgument->value->items === [];
        }

        try {
            $value = AstLiteralEvaluator::evaluate($bodyArgument->value);
        } catch (NonLiteralValueException) {
            return false;
        }

        return $value === '' || $value === null;
    }

    /**
     * Resolves the documented status: an explicit call-site `status` argument wins, then the
     * helper's own `status` parameter default, then the construction's literal status argument, then
     * the construction's own default. False when a present status is not statically readable.
     *
     * @param array{bodyArgumentName: ?string, bodyArgumentPosition: ?int, statusArgumentPosition: int} $construction
     * @param array<int, Arg>                                                                           $callArguments
     * @param class-string                                                                              $callerClass
     */
    private function resolveStatus(
        MethodCall|New_ $core,
        array $construction,
        ReflectionMethod $method,
        array $callArguments,
        string $callerClass,
    ): int|false {
        $statusParameterIndex = $this->statusParameterIndex($method);

        $callStatusArgument = CallArgumentResolver::argument(
            $callArguments,
            'status',
            $statusParameterIndex ?? -1,
        );

        if ($callStatusArgument !== null) {
            $value = $callStatusArgument->unpack ? null : $this->literalValueOf($callStatusArgument->value, $callerClass);

            return is_int($value) ? $value : false;
        }

        if ($statusParameterIndex !== null) {
            $parameter = $method->getParameters()[$statusParameterIndex];

            try {
                if ($parameter->isDefaultValueAvailable() && is_int($default = $parameter->getDefaultValue())) {
                    return $default;
                }
            } catch (ReflectionException) {
                // An unreadable default falls through to the construction's own status argument.
            }
        }

        $constructionStatusArgument = CallArgumentResolver::argument(
            $core->getArgs(),
            'status',
            $construction['statusArgumentPosition'],
        );

        if ($constructionStatusArgument !== null) {
            $value = $constructionStatusArgument->unpack
                ? null
                : $this->literalValueOf($constructionStatusArgument->value, $method->getDeclaringClass()->getName());

            return is_int($value) ? $value : false;
        }

        // noContent() defaults to 204; make()/Response()/JsonResponse() default to 200.
        return $construction['bodyArgumentName'] === null ? 204 : 200;
    }

    /** The zero-based position of the helper's `status` parameter, or null when it has none. */
    private function statusParameterIndex(ReflectionMethod $method): ?int
    {
        foreach ($method->getParameters() as $index => $parameter) {
            if ($parameter->getName() === 'status') {
                return $index;
            }
        }

        return null;
    }

    /** @param ?class-string $selfClass */
    private function literalValueOf(Expr $expression, ?string $selfClass = null): mixed
    {
        try {
            return AstLiteralEvaluator::evaluate($expression, $selfClass);
        } catch (NonLiteralValueException) {
            return null;
        }
    }
}
