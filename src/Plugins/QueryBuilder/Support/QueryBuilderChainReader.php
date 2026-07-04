<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Support;

use Illuminate\Container\Attributes\Scoped;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\CallArgumentResolver;
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
 * Reads `allowedFilters` / `allowedSorts` / `allowedIncludes` calls from a controller method body
 * and collects the literal wire names from their allow-lists.
 *
 * Only unconditional chains rooted at `Spatie\QueryBuilder\QueryBuilder::for(…)` are scanned.
 * Non-literal elements are dropped; partially unreadable calls are flagged on the scan result.
 *
 * @internal
 */
#[Scoped]
final class QueryBuilderChainReader
{
    public const int STATEMENT_LIMIT = 10;

    /**
     * @noinspection ClassConstantCanBeUsedInspection
     */
    private const string QUERY_BUILDER_CLASS = 'Spatie\\QueryBuilder\\QueryBuilder';

    /** Allow-list method name (lowercased) to kind. */
    private const array ALLOWED_CALL_KINDS = [
        'allowedfilters' => 'filters',
        'allowedsorts' => 'sorts',
        'allowedincludes' => 'includes',
    ];

    /** Kind to canonical call name (for degrade notes). */
    private const array CANONICAL_CALL_NAMES = [
        'filters' => 'allowedFilters',
        'sorts' => 'allowedSorts',
        'includes' => 'allowedIncludes',
    ];

    /**
     * Kind to Spatie value-object class whose static constructors may appear as allowlist elements.
     *
     * @noinspection ClassConstantCanBeUsedInspection
     */
    private const array VALUE_OBJECT_CLASSES = [
        'filters' => 'Spatie\\QueryBuilder\\AllowedFilter',
        'sorts' => 'Spatie\\QueryBuilder\\AllowedSort',
        'includes' => 'Spatie\\QueryBuilder\\AllowedInclude',
    ];

    /** Kind to whitelisted static constructors (lowercased); each takes the wire name as `$name`. */
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

        // A builder root or allowed* call inside a conditional counts as detection even though the
        // chain itself cannot be read there.
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
     * Whether the call's receiver spine roots at `Spatie\QueryBuilder\QueryBuilder::for(…)`.
     */
    private function rootsAtBuilder(MethodCall $call): bool
    {
        $receiver = $call->var;

        while ($receiver instanceof MethodCall) {
            $receiver = $receiver->var;
        }

        return $this->isBuilderRoot($receiver);
    }

    /** Whether the node is a static `for()` call on the Spatie `QueryBuilder` FQCN. */
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

    // region Allowlist elements

    /**
     * Element expressions of one allowlist call; handles both the array-literal and variadic forms.
     *
     * @return list<Expr>
     */
    private function elementExpressions(MethodCall $call): array
    {
        $rawArguments = $call->getArgs();
        $arguments = count($rawArguments) === 1 && $rawArguments[0]->value instanceof Array_
            ? $rawArguments[0]->value->items
            : $rawArguments;
        $elements = [];

        foreach ($arguments as $argument) {
            $elements[] = $argument->value;
        }

        return $elements;
    }

    /**
     * The wire name from one allow-list element, or null when not statically readable.
     * Sort names shed the leading `-` direction marker.
     */
    private function elementName(Expr $element, string $kind): ?string
    {
        // Instance modifiers never change the wire name; walk to the underlying constructor.
        $root = $element;

        while ($root instanceof MethodCall) {
            $root = $root->var;
        }

        if ($root instanceof StaticCall) {
            return $this->valueObjectName($root, $kind);
        }

        try {
            $value = AstLiteralEvaluator::evaluate($element);
        } catch (NonLiteralValueException) {
            return null;
        }

        return is_string($value) ? $this->normaliseName($value, $kind) : null;
    }

    /**
     * The wire name from a Spatie value-object static constructor (first `name` argument, literal
     * string). `AllowedFilter::trashed()` falls back to `'trashed'`. Returns null for unrecognised
     * classes or constructors.
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

        $nameArgument = CallArgumentResolver::argument($call->getArgs(), 'name', 0);

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

    /** Strips the leading `-` direction marker from sort names. Returns null for empty strings. */
    private function normaliseName(string $name, string $kind): ?string
    {
        if ($kind === 'sorts') {
            $name = ltrim($name, '-');
        }

        return $name === '' ? null : $name;
    }

    // endregion
}
