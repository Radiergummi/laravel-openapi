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
 * Bounded static scanner for `$this->middleware(...)` registrations in a controller constructor,
 * used as a fallback when runtime instantiation fails during generation.
 *
 * Reads only straight-line, literal registrations ({@see ConditionalContextPolicy}): conditional
 * middleware would overstate the contract. Non-literal names, unknown chain links, and spread
 * arguments are refused; the caller degrades gracefully.
 *
 * @internal
 */
#[Scoped]
final class ConstructorMiddlewareScanner
{
    public const int STATEMENT_LIMIT = 10;

    /** @var array<class-string, ConstructorMiddlewareScan> */
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

        // Straight-line calls not consumed above are unreadable; those only reachable under the
        // inclusive policy are conditionally applied.
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
     * Returns the chain root and its fluent links for a `$this->middleware(...)` statement, or null.
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

        // Outermost-first; the innermost link is the chain root.
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
     * Normalises a string or string[] to a list, or returns null (mirrors Laravel's scoping args).
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
