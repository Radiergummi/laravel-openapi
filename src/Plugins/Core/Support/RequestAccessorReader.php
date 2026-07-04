<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Http\Request;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure as ClosureExpression;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\CallArgumentResolver;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\NonLiteralValueException;
use ReflectionMethod;
use ReflectionNamedType;

use function array_key_exists;
use function array_shift;
use function explode;
use function in_array;
use function is_a;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function str_contains;

/**
 * Scans the first {@see self::STATEMENT_LIMIT} statements of a controller method for the
 * whitelisted request accessor shapes and reports each as an {@see AccessorRead} with its
 * parameter name, location, and inferred type. `query`/`input`/`string`/`integer`/`boolean`
 * map to `query` parameters; `cookie` and `header` map to their own locations.
 *
 * Query keys are converted to wire notation (`filter.name` → `filter[name]`); cookie/header
 * names keep the raw literal token. Default values are kept only when their PHP type matches
 * the accessor's inferred type. A closure or arrow function that re-declares the receiver
 * variable name shadows it for its subtree.
 *
 * @internal
 */
#[Scoped]
final readonly class RequestAccessorReader
{
    public const int STATEMENT_LIMIT = 10;

    /**
     * Accessor method → parameter location. Cookie and header names are string tokens, so their
     * keys skip the dotted→bracket wire-name transform applied to query keys.
     */
    public const array ACCESSOR_LOCATIONS = [
        'query' => 'query',
        'input' => 'query',
        'string' => 'query',
        'integer' => 'query',
        'boolean' => 'query',
        'cookie' => 'cookie',
        'header' => 'header',
    ];

    /**
     * Accessor method → OpenAPI type. Cookies and headers are string-valued on the wire.
     */
    private const array ACCESSOR_TYPES = [
        'query' => 'string',
        'input' => 'string',
        'string' => 'string',
        'integer' => 'integer',
        'boolean' => 'boolean',
        'cookie' => 'string',
        'header' => 'string',
    ];

    /**
     * Accessors that do not name a scalar type: the untyped bag reads and the string-valued
     * cookie/header locations. A typed read wins over these for the same query name.
     */
    private const array UNTYPED_ACCESSORS = ['query', 'input', 'cookie', 'header'];

    public function __construct(
        private MethodBodyScanner $scanner,
    ) {}

    public function read(ReflectionMethod $method): AccessorScanResult
    {
        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);

        if ($statements === []) {
            return new AccessorScanResult();
        }

        $requestParameterName = $this->requestParameterName($method);

        /** @var list<MethodCall> $calls */
        $calls = [];

        foreach ($statements as $statement) {
            $this->collectAccessorCalls($statement, $requestParameterName, $calls);
        }

        /** @var list<AccessorRead> $reads */
        $reads = [];

        /** @var list<string> $unreadableAccessors */
        $unreadableAccessors = [];

        foreach ($calls as $call) {
            $accessor = $call->name instanceof Identifier ? $call->name->toLowerString() : '';
            $keyArgument = CallArgumentResolver::argument($call->getArgs(), 'key', 0);

            if ($keyArgument === null) {
                // Zero-argument query()/cookie()/header() reads the whole bag, not a named parameter.
                continue;
            }

            try {
                $key = AstLiteralEvaluator::evaluate($keyArgument->value);
            } catch (NonLiteralValueException) {
                $unreadableAccessors[] = $accessor;

                continue;
            }

            $location = self::ACCESSOR_LOCATIONS[$accessor];

            // The dotted→bracket transform is query-only; a cookie/header name is a literal token.
            $name = is_string($key)
                ? ($location === 'query' ? $this->wireName($key) : $this->literalName($key))
                : null;

            if ($name === null) {
                $unreadableAccessors[] = $accessor;

                continue;
            }

            $type = self::ACCESSOR_TYPES[$accessor];

            $reads[] = new AccessorRead(
                name: $name,
                accessor: $accessor,
                location: $location,
                type: $type,
                typed: !in_array($accessor, self::UNTYPED_ACCESSORS, true),
                default: $this->defaultValueOf($call, $type),
            );
        }

        return new AccessorScanResult($reads, $unreadableAccessors);
    }

    // region Call-shape matching

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

    /**
     * @param list<MethodCall> $calls
     */
    private function collectAccessorCalls(Node $node, ?string $requestParameterName, array &$calls): void
    {
        if ($this->shadowsReceiver($node, $requestParameterName)) {
            $requestParameterName = null;
        }

        if ($node instanceof MethodCall && $this->isAccessorCall($node, $requestParameterName)) {
            $calls[] = $node;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            /** @var mixed $children */
            $children = $node->{$subNodeName};

            foreach (is_array($children) ? $children : [$children] as $child) {
                if ($child instanceof Node) {
                    $this->collectAccessorCalls($child, $requestParameterName, $calls);
                }
            }
        }
    }

    /**
     * Whether the node opens a function scope that re-declares the receiver variable name.
     */
    private function shadowsReceiver(Node $node, ?string $requestParameterName): bool
    {
        if (
            $requestParameterName === null
            || (!$node instanceof ClosureExpression && !$node instanceof ArrowFunction)
        ) {
            return false;
        }

        return array_any(
            $node->params,
            fn(Node\Param $parameter): bool
                => $parameter->var instanceof Variable
                && $parameter->var->name === $requestParameterName,
        );
    }

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

    // endregion

    // region Argument resolution

    /**
     * Converts a dotted key to wire notation (`filter.name` → `filter[name]`). Returns null for
     * wildcard or empty segments.
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

    /**
     * Returns a cookie/header name unchanged. These are literal tokens (`X-Api-Key`, `session`),
     * never bracketed; only empty or wildcard names are rejected.
     */
    private function literalName(string $key): ?string
    {
        return $key === '' || str_contains($key, '*') ? null : $key;
    }

    /**
     * Returns the literal default only when its PHP type matches the inferred parameter type;
     * null, non-literal, or mismatched defaults are omitted.
     */
    private function defaultValueOf(MethodCall $call, string $type): mixed
    {
        $defaultArgument = CallArgumentResolver::argument($call->getArgs(), 'default', 1);

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

    // endregion
}
