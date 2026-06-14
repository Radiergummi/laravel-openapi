<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Support;

use Illuminate\Container\Attributes\Scoped;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\ConditionalContextPolicy;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\NonLiteralValueException;
use Radiergummi\OpenApi\Support\MethodBody\StatementNodeFinder;
use ReflectionMethod;

use function array_key_exists;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_string;
use function ltrim;

/**
 * Tier-1 whitelist matcher for the `spatie/laravel-query-builder` fluent chain in a controller
 * method body (epic #5, issue #15).
 *
 * Scans the first {@see self::STATEMENT_LIMIT} top-level statements for `allowedFilters` /
 * `allowedSorts` / `allowedIncludes` calls whose receiver spine roots at a static
 * `Spatie\QueryBuilder\QueryBuilder::for(...)` call — matched on the **resolved** FQCN, so a
 * same-named impostor class never does — and collects the literal wire names from their
 * allow-lists. Other links on the same chain (`->defaultSort()`, `->where()`, `->paginate()`, …)
 * are walked through: chain termination is irrelevant to the parameters the endpoint accepts.
 *
 * Only single-expression chains on the unconditional path participate — as a statement
 * expression, an assignment right-hand side, or a `return` value. A builder assigned to a
 * variable and mutated across statements needs dataflow and is refused (Tier 2, see epic #5);
 * the scan reports enough evidence for the caller to log the refusal.
 *
 * Allow-list elements resolve from string literals (via {@see AstLiteralEvaluator}, so
 * class-constant strings work too) and from Spatie's value-object static constructors
 * (`AllowedFilter::exact('status')`, `AllowedSort::field('created_at')`, …), whose first
 * argument is always the public wire name. Fluent instance modifiers on such a constructor
 * (`->nullable()`, `->default()`, `->ignore()`, `->delimiter()`, `->defaultDirection()`) are
 * walked through — they change server-side semantics, never the wire name. Spatie's variadic
 * form (`allowedSorts('name', 'created_at')`) reads the same way. A non-literal element is
 * dropped and the remaining ones kept; the call is reported as partially unreadable.
 *
 * @internal
 */
#[Scoped]
final class QueryBuilderChainReader
{
    public const int STATEMENT_LIMIT = 10;

    private const string QUERY_BUILDER_CLASS = 'Spatie\\QueryBuilder\\QueryBuilder';

    /**
     * Allow-list method name (lowercased) → the kind of wire name it declares.
     */
    private const array ALLOWED_CALL_KINDS = [
        'allowedfilters' => 'filters',
        'allowedsorts' => 'sorts',
        'allowedincludes' => 'includes',
    ];

    /**
     * Kind → canonical allow-list call name, for human-readable degrade notes.
     */
    private const array CANONICAL_CALL_NAMES = [
        'filters' => 'allowedFilters',
        'sorts' => 'allowedSorts',
        'includes' => 'allowedIncludes',
    ];

    /**
     * Kind → the Spatie value-object class whose static constructors may appear as allow-list
     * elements. Matched on the resolved FQCN, like the chain root.
     */
    private const array VALUE_OBJECT_CLASSES = [
        'filters' => 'Spatie\\QueryBuilder\\AllowedFilter',
        'sorts' => 'Spatie\\QueryBuilder\\AllowedSort',
        'includes' => 'Spatie\\QueryBuilder\\AllowedInclude',
    ];

    /**
     * Kind → whitelisted static constructors (lowercased) on the matching value-object class.
     * Every one of them takes the public wire name as its first parameter (`name`); the
     * constructor kind only changes the SQL semantics, never the parameter name.
     */
    private const array VALUE_OBJECT_CONSTRUCTORS = [
        'filters' => [
            'exact',
            'partial',
            'beginswith',
            'endswith',
            'belongsto',
            'scope',
            'callback',
            'trashed',
            'custom',
            'operator',
            'groupor',
            'groupand',
        ],
        'sorts' => ['field', 'custom', 'callback'],
        'includes' => ['relationship', 'count', 'exists', 'min', 'max', 'sum', 'avg', 'callback', 'custom'],
    ];

    /**
     * Memoised scans per `Class::method`, so repeated resolution (one per route verb, plus lint)
     * parses once per generation run.
     *
     * @var array<string, QueryBuilderChainScan>
     */
    private array $cache = [];

    private readonly StatementNodeFinder $statementNodeFinder;

    public function __construct(
        private readonly MethodBodyScanner $scanner,
    ) {
        $this->statementNodeFinder = new StatementNodeFinder();
    }

    public function read(ReflectionMethod $method): QueryBuilderChainScan
    {
        $key = $method->getDeclaringClass()->getName() . '::' . $method->getName();

        return $this->cache[$key] ??= $this->scan($method);
    }

    private function scan(ReflectionMethod $method): QueryBuilderChainScan
    {
        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);

        if ($statements === []) {
            return new QueryBuilderChainScan();
        }

        // Detection (for the caller's degrade note) is broader than matching: a builder root or
        // an allowed* call inside a conditional context is evidence of Spatie usage even though
        // the chain itself is refused there.
        $builderDetected = $this->statementNodeFinder->findFirst(
            $statements,
            ConditionalContextPolicy::IncludeConditionalContexts,
            $this->isBuilderRoot(...),
        ) !== null;
        $allowedCallDetected = $this->statementNodeFinder->findFirst(
            $statements,
            ConditionalContextPolicy::IncludeConditionalContexts,
            static fn(Node $node): bool
                    => $node instanceof MethodCall
                    && $node->name instanceof Identifier
                    && array_key_exists($node->name->toLowerString(), self::ALLOWED_CALL_KINDS),
        ) !== null;

        /** @var list<MethodCall> $matchedCalls */
        $matchedCalls = [];

        foreach (
            $this->statementNodeFinder->findAll(
                $statements,
                ConditionalContextPolicy::SkipConditionalContexts,
                fn(Node $node): bool
                    => $node instanceof MethodCall
                    && $node->name instanceof Identifier
                    && array_key_exists($node->name->toLowerString(), self::ALLOWED_CALL_KINDS)
                    && !$node->isFirstClassCallable()
                    && $this->rootsAtBuilder($node),
            ) as $call
        ) {
            assert($call instanceof MethodCall);
            $matchedCalls[] = $call;
        }

        /** @var array<string, list<string>> $names */
        $names = ['filters' => [], 'sorts' => [], 'includes' => []];

        /** @var list<string> $unreadableCalls */
        $unreadableCalls = [];

        foreach ($matchedCalls as $call) {
            assert($call->name instanceof Identifier);
            $kind = self::ALLOWED_CALL_KINDS[$call->name->toLowerString()];
            $droppedElement = false;

            foreach ($this->elementExpressions($call) as $element) {
                $name = $this->elementName($element, $kind);

                if ($name === null) {
                    $droppedElement = true;

                    continue;
                }

                $names[$kind][] = $name;
            }

            if ($droppedElement) {
                $unreadableCalls[] = self::CANONICAL_CALL_NAMES[$kind];
            }
        }

        return new QueryBuilderChainScan(
            filters: array_values(array_unique($names['filters'])),
            sorts: array_values(array_unique($names['sorts'])),
            includes: array_values(array_unique($names['includes'])),
            builderDetected: $builderDetected,
            allowedCallDetected: $allowedCallDetected,
            unreadableCalls: array_values(array_unique($unreadableCalls)),
        );
    }

    // region Chain rooting

    /**
     * Whether the call's receiver spine — walked through any number of chained method calls —
     * roots at a `Spatie\QueryBuilder\QueryBuilder::for(...)` static call. The links in between
     * are irrelevant: they cannot change which allow-lists the builder accepts.
     */
    private function rootsAtBuilder(MethodCall $call): bool
    {
        $receiver = $call->var;

        while ($receiver instanceof MethodCall) {
            $receiver = $receiver->var;
        }

        return $this->isBuilderRoot($receiver);
    }

    /**
     * Whether the node is a static `for()` call on the resolved Spatie `QueryBuilder` FQCN —
     * never an impostor class of the same short name, never a dynamic class expression.
     */
    private function isBuilderRoot(Node $node): bool
    {
        return $node instanceof StaticCall
            && $node->class instanceof Name
            && $node->class->toString() === self::QUERY_BUILDER_CLASS
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === 'for'
            && !$node->isFirstClassCallable();
    }

    // endregion

    // region Allow-list elements

    /**
     * The element expressions of one allow-list call: the items of a single array-literal
     * argument, or — Spatie accepts both forms — each argument of the variadic form.
     *
     * @return list<Expr>
     */
    private function elementExpressions(MethodCall $call): array
    {
        $arguments = $call->getArgs();

        if (count($arguments) === 1 && $arguments[0]->value instanceof Array_) {
            $elements = [];

            foreach ($arguments[0]->value->items as $item) {
                $elements[] = $item->value;
            }

            return $elements;
        }

        $elements = [];

        foreach ($arguments as $argument) {
            $elements[] = $argument->value;
        }

        return $elements;
    }

    /**
     * The wire name one allow-list element declares, or null when it is not statically
     * readable: a string literal (class-constant strings included), or a whitelisted Spatie
     * value-object constructor of the matching kind. Sort names shed Spatie's leading `-`
     * direction marker.
     */
    private function elementName(Expr $element, string $kind): ?string
    {
        // Spatie's instance modifiers (->nullable(), ->default(), ->ignore(), ->delimiter(),
        // AllowedSort::...->defaultDirection()) wrap the value-object constructor but never
        // change the wire name — walk the method-call spine to the underlying constructor,
        // exactly as the chain receiver is walked in rootsAtBuilder().
        $root = $element;

        while ($root instanceof MethodCall) {
            $root = $root->var;
        }

        if ($root instanceof StaticCall) {
            return $this->valueObjectName($root, $kind);
        }

        // A bare literal element carries no spine, so evaluate the original node directly.
        try {
            $value = AstLiteralEvaluator::evaluate($element);
        } catch (NonLiteralValueException) {
            return null;
        }

        return is_string($value) ? $this->normaliseName($value, $kind) : null;
    }

    /**
     * The wire name declared by a Spatie value-object static constructor — the first (`name`)
     * argument, which must be a literal string. `AllowedFilter::trashed()` without arguments
     * falls back to Spatie's own default name. A constructor on the wrong class for the
     * allow-list kind, or off the whitelist, stays unreadable.
     */
    private function valueObjectName(StaticCall $call, string $kind): ?string
    {
        if (
            !$call->class instanceof Name
            || $call->class->toString() !== self::VALUE_OBJECT_CLASSES[$kind]
            || !$call->name instanceof Identifier
            || !in_array($call->name->toLowerString(), self::VALUE_OBJECT_CONSTRUCTORS[$kind], true)
            || $call->isFirstClassCallable()
        ) {
            return null;
        }

        $nameArgument = $this->argument($call->getArgs(), 0, 'name');

        if ($nameArgument === null) {
            return $call->name->toLowerString() === 'trashed' ? 'trashed' : null;
        }

        try {
            $value = AstLiteralEvaluator::evaluate($nameArgument->value);
        } catch (NonLiteralValueException) {
            return null;
        }

        return is_string($value) ? $this->normaliseName($value, $kind) : null;
    }

    /**
     * Resolves an argument by position or by its named-argument name. Positional arguments
     * always precede named ones, so an unnamed argument's list index is its position.
     *
     * @param array<int, Arg> $arguments
     */
    private function argument(array $arguments, int $position, string $name): ?Arg
    {
        foreach ($arguments as $index => $argument) {
            $matches = $argument->name === null
                ? $index === $position
                : $argument->name->toString() === $name;

            if ($matches) {
                return $argument;
            }
        }

        return null;
    }

    /**
     * Sort names shed the leading `-` direction marker, mirroring Spatie's own constructor —
     * `allowedSorts(['-created_at'])` accepts the `created_at` field in either direction.
     * An empty name never documents a parameter.
     */
    private function normaliseName(string $name, string $kind): ?string
    {
        if ($kind === 'sorts') {
            $name = ltrim($name, '-');
        }

        return $name === '' ? null : $name;
    }

    // endregion
}
