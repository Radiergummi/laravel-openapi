<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Http\Request;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\ConditionalContextPolicy;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\NonLiteralValueException;
use Radiergummi\OpenApi\Support\MethodBody\StatementNodeFinder;
use ReflectionMethod;
use ReflectionNamedType;

use function array_key_exists;
use function array_shift;
use function explode;
use function in_array;
use function is_a;
use function is_bool;
use function is_int;
use function is_string;
use function str_contains;

/**
 * Tier-1 whitelist matcher for request-accessor reads in a controller method body.
 *
 * Scans the first {@see self::STATEMENT_LIMIT} top-level statements for exactly five accessor
 * shapes on the request — `query('sort')`, `input('q')`, `string('name')`, `integer('page')`,
 * `boolean('active')` — and reports each as a {@see QueryAccessorRead} carrying the parameter
 * name and the type the accessor implies.
 *
 * The receiver must be the method's `Illuminate\Http\Request`(-subclass)-typed parameter
 * variable or a zero-argument `request()` helper call; any other object with a same-named
 * method (an Eloquent builder's `query()`, say) never matches. The parameter name is the first
 * string-literal argument — positional or named (`key:`); a dotted key is reported in wire
 * notation (`filter.name` → `filter[name]`). A literal default value (second argument or
 * `default:`) is kept when its PHP type matches the accessor's inferred type.
 *
 * Matching descends into conditional contexts ({@see ConditionalContextPolicy::IncludeConditionalContexts}):
 * unlike a request body, a read claims nothing beyond "this parameter is consumed" — a
 * `$request->boolean('active')` inside an `if` branch or a `->when(...)` closure is still a
 * read. No variable tracking, no cross-call dataflow (Tier 2 is refused, see epic #5).
 *
 * @internal
 */
#[Scoped]
final readonly class RequestQueryAccessorReader
{
    public const int STATEMENT_LIMIT = 10;

    /**
     * Accessor method → OpenAPI type. Deliberately only the spec'd five (#11): `get()`,
     * `has()`, `filled()`, `date()`, `enum()`, `float()` and friends stay off the whitelist.
     */
    private const array ACCESSOR_TYPES = [
        'query' => 'string',
        'input' => 'string',
        'string' => 'string',
        'integer' => 'integer',
        'boolean' => 'boolean',
    ];

    private const array UNTYPED_ACCESSORS = ['query', 'input'];

    private StatementNodeFinder $statementNodeFinder;

    public function __construct(
        private MethodBodyScanner $scanner,
    ) {
        $this->statementNodeFinder = new StatementNodeFinder();
    }

    public function read(ReflectionMethod $method): QueryAccessorScanResult
    {
        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);

        if ($statements === []) {
            return new QueryAccessorScanResult();
        }

        $requestParameterName = $this->requestParameterName($method);

        $calls = $this->statementNodeFinder->findAll(
            $statements,
            ConditionalContextPolicy::IncludeConditionalContexts,
            fn(Node $node): bool => $this->isAccessorCall($node, $requestParameterName),
        );

        /** @var list<QueryAccessorRead> $reads */
        $reads = [];

        /** @var list<string> $unreadableAccessors */
        $unreadableAccessors = [];

        foreach ($calls as $call) {
            /** @var MethodCall $call */
            $accessor = $call->name instanceof Identifier ? $call->name->toLowerString() : '';
            $keyArgument = $this->argument($call->getArgs(), 0, 'key');

            if ($keyArgument === null) {
                // Zero-argument query()/input() reads the whole bag — not a named parameter.
                continue;
            }

            try {
                $key = AstLiteralEvaluator::evaluate($keyArgument->value);
            } catch (NonLiteralValueException) {
                $unreadableAccessors[] = $accessor;

                continue;
            }

            $name = is_string($key) ? $this->wireName($key) : null;

            if ($name === null) {
                $unreadableAccessors[] = $accessor;

                continue;
            }

            $type = self::ACCESSOR_TYPES[$accessor];

            $reads[] = new QueryAccessorRead(
                name: $name,
                accessor: $accessor,
                type: $type,
                typed: !in_array($accessor, self::UNTYPED_ACCESSORS, true),
                default: $this->defaultValueOf($call, $type),
            );
        }

        return new QueryAccessorScanResult($reads, $unreadableAccessors);
    }

    // region Call-shape matching

    /**
     * Whether the node is a whitelisted accessor call on the request: the method's
     * `Illuminate\Http\Request`-typed parameter variable or a zero-argument `request()`
     * helper call.
     */
    private function isAccessorCall(Node $node, ?string $requestParameterName): bool
    {
        if (
            !$node instanceof MethodCall
            || !$node->name instanceof Identifier
            || !array_key_exists($node->name->toLowerString(), self::ACCESSOR_TYPES)
            || $node->isFirstClassCallable()
        ) {
            return false;
        }

        $receiver = $node->var;

        if (
            $receiver instanceof Variable
            && is_string($receiver->name)
            && $requestParameterName !== null
            && $receiver->name === $requestParameterName
        ) {
            return true;
        }

        return $receiver instanceof FuncCall
            && $receiver->name instanceof Name
            && $receiver->name->toLowerString() === 'request'
            && !$receiver->isFirstClassCallable()
            && $receiver->getArgs() === [];
    }

    /**
     * Returns the name of the first `Illuminate\Http\Request`-typed parameter, or null.
     */
    private function requestParameterName(ReflectionMethod $method): ?string
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (
                $type instanceof ReflectionNamedType
                && !$type->isBuiltin()
                && is_a($type->getName(), Request::class, true)
            ) {
                return $parameter->getName();
            }
        }

        return null;
    }

    // endregion

    // region Argument resolution

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
     * The accessor's literal default value (second argument or `default:`), kept only when its
     * PHP type matches the inferred parameter type — `integer('per_page', 25)` carries 25, but
     * `query('page', 1)` does not pin an integer default onto a string parameter. A null,
     * non-literal, or type-mismatched default is simply omitted; the parameter itself is still
     * documented.
     */
    private function defaultValueOf(MethodCall $call, string $type): mixed
    {
        $defaultArgument = $this->argument($call->getArgs(), 1, 'default');

        if ($defaultArgument === null) {
            return null;
        }

        try {
            $default = AstLiteralEvaluator::evaluate($defaultArgument->value);
        } catch (NonLiteralValueException) {
            return null;
        }

        $matchesType = match ($type) {
            'string' => is_string($default),
            'integer' => is_int($default),
            'boolean' => is_bool($default),
            default => false,
        };

        return $matchesType ? $default : null;
    }

    /**
     * Converts a dotted accessor key to its query-string wire notation (`filter.name` →
     * `filter[name]`). Returns null for keys that name no single parameter — a wildcard
     * segment or an empty segment.
     */
    private function wireName(string $key): ?string
    {
        if ($key === '' || str_contains($key, '*')) {
            return null;
        }

        $segments = explode('.', $key);
        $name = array_shift($segments);

        foreach ($segments as $segment) {
            if ($segment === '') {
                return null;
            }

            $name .= '[' . $segment . ']';
        }

        return $name === '' ? null : $name;
    }

    // endregion
}
