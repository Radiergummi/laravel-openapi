<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

use Illuminate\Container\Attributes\Scoped;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Expression;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\ConditionalContextPolicy;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\NonLiteralValueException;
use Radiergummi\OpenApi\Support\MethodBody\StatementNodeFinder;
use ReflectionClass;

use function array_slice;
use function count;
use function end;
use function is_array;
use function is_string;
use function spl_object_id;

/**
 * Tier-1 whitelist matcher for imperative `$this->middleware(...)` registrations in a controller
 * constructor (epic #5, issue #16).
 *
 * Scans the first {@see self::STATEMENT_LIMIT} top-level statements of `__construct` for
 * statement-level `$this->middleware(<literal>)` chains and reads the literal middleware names
 * together with their action scoping — the fluent `->only(...)` / `->except(...)` links and the
 * equivalent `['only' => ..., 'except' => ...]` options-array argument. Literals resolve via
 * {@see AstLiteralEvaluator}, so class-constant strings work.
 *
 * This is the *static fallback* for Laravel's own runtime resolution: `Route::gatherMiddleware()`
 * already reads constructor middleware by instantiating the controller, so the scan only runs when
 * instantiation fails in the generation context (see {@see RouteMiddlewareGatherer}). Receiver
 * discipline is strict — only calls on the literal `$this` match. Inherited constructors work
 * naturally: `ReflectionClass::getConstructor()` reflects the declaring class, so the scanner
 * reads the base controller's file.
 *
 * Only straight-line registrations participate ({@see ConditionalContextPolicy}): a
 * `$this->middleware()` inside an `if` documents conditional auth as unconditional, which
 * overstates the contract — it is refused and reported as conditional evidence instead.
 * Non-literal names or scoping, unknown chain links, and spread arguments are refused likewise
 * and reported as unreadable; the caller degrades with a generation-log note.
 *
 * @internal
 */
#[Scoped]
final class ConstructorMiddlewareScanner
{
    public const int STATEMENT_LIMIT = 10;

    /**
     * Memoized scans per controller class: one parse per generation run, however, many routes
     * point at the controller.
     *
     * @var array<class-string, ConstructorMiddlewareScan>
     */
    private array $cache = [];

    private readonly StatementNodeFinder $statementNodeFinder;

    public function __construct(private readonly MethodBodyScanner $scanner)
    {
        $this->statementNodeFinder = new StatementNodeFinder();
    }

    /**
     * @param ReflectionClass<object> $controller
     */
    public function scan(ReflectionClass $controller): ConstructorMiddlewareScan
    {
        return $this->cache[$controller->getName()] ??= $this->scanConstructor($controller);
    }

    /**
     * @param ReflectionClass<object> $controller
     */
    private function scanConstructor(ReflectionClass $controller): ConstructorMiddlewareScan
    {
        $constructor = $controller->getConstructor();

        if ($constructor === null) {
            return new ConstructorMiddlewareScan();
        }

        $statements = $this->scanner->firstStatements($constructor, self::STATEMENT_LIMIT);

        if ($statements === []) {
            return new ConstructorMiddlewareScan();
        }

        $entries = [];
        $unreadableCallDetected = false;

        /** @var array<int, true> $consumedCalls spl_object_id set of matched middleware call nodes */
        $consumedCalls = [];

        foreach ($statements as $statement) {
            $registration = $this->registrationChain($statement);

            if ($registration === null) {
                continue;
            }

            [$middlewareCall, $chainLinks] = $registration;
            $consumedCalls[spl_object_id($middlewareCall)] = true;

            $entry = $this->entryFrom($middlewareCall, $chainLinks);

            if ($entry === null) {
                $unreadableCallDetected = true;

                continue;
            }

            $entries[] = $entry;
        }

        // Detection is broader than matching: a middleware call reachable on the straight-line
        // path but not consumed above (nested in an argument, an unmatched chain shape) is
        // unreadable; one reachable only under the inclusive policy is conditionally applied.
        $straightLineCalls = $this->statementNodeFinder->findAll(
            $statements,
            ConditionalContextPolicy::SkipConditionalContexts,
            $this->isMiddlewareRegistration(...),
        );

        foreach ($straightLineCalls as $call) {
            if (!isset($consumedCalls[spl_object_id($call)])) {
                $unreadableCallDetected = true;
            }
        }

        $allCalls = $this->statementNodeFinder->findAll(
            $statements,
            ConditionalContextPolicy::IncludeConditionalContexts,
            $this->isMiddlewareRegistration(...),
        );

        return new ConstructorMiddlewareScan(
            entries: $entries,
            unreadableCallDetected: $unreadableCallDetected,
            conditionalCallDetected: count($allCalls) > count($straightLineCalls),
        );
    }

    /**
     * Unwraps a top-level statement into a method-call chain rooted at `$this->middleware(...)`:
     * the canonical registration idiom, as a bare statement or an assignment's right-hand side.
     * Returns the root call plus the chain links above it (`->only()` / `->except()` / …), or
     * null when the statement is no such chain.
     *
     * @return null|array{MethodCall, list<MethodCall>}
     */
    private function registrationChain(Node $statement): ?array
    {
        if (!$statement instanceof Expression) {
            return null;
        }

        $expression = $statement->expr;

        if ($expression instanceof Assign) {
            $expression = $expression->expr;
        }

        if (!$expression instanceof MethodCall) {
            return null;
        }

        // Outermost call first; the innermost link is the chain root.
        $links = [];
        $current = $expression;

        while ($current instanceof MethodCall) {
            $links[] = $current;
            $current = $current->var;
        }

        $root = end($links);

        if (!$this->isMiddlewareRegistration($root)) {
            return null;
        }

        return [$root, array_slice($links, 0, -1)];
    }

    private function isMiddlewareRegistration(Node $node): bool
    {
        return $node instanceof MethodCall
            && $node->var instanceof Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === 'middleware'
            && !$node->isFirstClassCallable();
    }

    /**
     * Reads one registration into an entry, or null when any part is not statically readable:
     * the middleware names (literal string or array of strings), the optional options-array
     * argument, and the fluent `only` / `except` chain links.
     *
     * @param list<MethodCall> $chainLinks
     *
     * @return null|array{names: list<string>, only: null|list<string>, except: null|list<string>}
     */
    private function entryFrom(MethodCall $middlewareCall, array $chainLinks): ?array
    {
        $arguments = $middlewareCall->getArgs();

        if (count($arguments) < 1 || count($arguments) > 2 || $this->hasSpreadArgument($arguments)) {
            return null;
        }

        $names = $this->stringList($this->literal($arguments[0]->value));

        if ($names === null) {
            return null;
        }

        $only = null;
        $except = null;

        if (isset($arguments[1])) {
            $options = $this->literal($arguments[1]->value);

            if (!is_array($options)) {
                return null;
            }

            if (isset($options['only']) && ($only = $this->stringList($options['only'])) === null) {
                return null;
            }

            if (isset($options['except']) && ($except = $this->stringList($options['except'])) === null) {
                return null;
            }
        }

        foreach ($chainLinks as $link) {
            if (!$link->name instanceof Identifier || $link->isFirstClassCallable()) {
                return null;
            }

            $linkArguments = $link->getArgs();

            if (count($linkArguments) !== 1 || $this->hasSpreadArgument($linkArguments)) {
                return null;
            }

            $methods = $this->stringList($this->literal($linkArguments[0]->value));

            if ($methods === null) {
                return null;
            }

            match ($link->name->toLowerString()) {
                'only' => $only = $methods,
                'except' => $except = $methods,
                default => $methods = null,
            };

            if ($methods === null) {
                return null;
            }
        }

        return ['names' => $names, 'only' => $only, 'except' => $except];
    }

    /**
     * @param array<Node\Arg> $arguments
     */
    private function hasSpreadArgument(array $arguments): bool
    {
        return array_any($arguments, fn(Node\Arg $argument): bool => $argument->unpack);
    }

    /**
     * Normalizes a literal value to a list of strings: a string becomes a one-element list
     * (Laravel `Arr::wrap()`s scoping arguments the same way); an array qualifies when every
     * value is a string. Anything else — including null from a failed literal read — refuses.
     *
     * @return null|list<string>
     */
    private function stringList(mixed $value): ?array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (!is_array($value)) {
            return null;
        }

        $strings = [];

        foreach ($value as $entry) {
            if (!is_string($entry)) {
                return null;
            }

            $strings[] = $entry;
        }

        return $strings;
    }

    private function literal(Node\Expr $expression): mixed
    {
        try {
            return AstLiteralEvaluator::evaluate($expression);
        } catch (NonLiteralValueException) {
            return null;
        }
    }
}
