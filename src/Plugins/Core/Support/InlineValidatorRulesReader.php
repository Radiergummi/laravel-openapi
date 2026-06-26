<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Http\Request;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\ConditionalContextPolicy;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\NonLiteralValueException;
use Radiergummi\OpenApi\Support\MethodBody\RuleFieldLiteralMapper;
use Radiergummi\OpenApi\Support\MethodBody\StatementNodeFinder;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

use function array_key_exists;
use function array_values;
use function is_a;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Scans the first {@see self::STATEMENT_LIMIT} top-level statements for four whitelisted validator
 * call shapes: `$request->validate()`, `$this->validate($request, ...)`, `Validator::make()`, and
 * `Request::validate()`. Conditional contexts are skipped. The rules argument may be an array
 * literal, a `$this->rules` property, or a zero-argument `$this->rules()` method call, optionally
 * subscripted with a literal key. Anything dynamic yields a degraded result.
 *
 * @internal
 */
#[Scoped]
final readonly class InlineValidatorRulesReader
{
    public const int STATEMENT_LIMIT = 10;

    private const string VALIDATOR_FACADE = 'Illuminate\Support\Facades\Validator';

    private const string REQUEST_FACADE = 'Illuminate\Support\Facades\Request';

    private StatementNodeFinder $statementNodeFinder;

    public function __construct(
        private MethodBodyScanner $scanner,
    ) {
        $this->statementNodeFinder = new StatementNodeFinder();
    }

    /**
     * Returns null when no whitelisted call is present; a degraded result when rules cannot be
     * read statically.
     */
    public function read(ReflectionMethod $method): ?InlineValidationScanResult
    {
        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);

        if ($statements === []) {
            return null;
        }

        $requestParameterName = $this->requestParameterName($method);

        // Straight-line statements only: a validate() that runs conditionally (an `if` branch,
        // a ternary arm, a short-circuit, a closure body) would be a guess as the request body.
        $call = $this->statementNodeFinder->findFirst(
            $statements,
            ConditionalContextPolicy::SkipConditionalContexts,
            fn(Node $node): bool => $this->rulesArgumentOf($node, $requestParameterName) instanceof Expr,
        );

        if ($call === null) {
            return null;
        }

        $rulesExpression = $this->rulesArgumentOf($call, $requestParameterName);

        if ($rulesExpression === null) {
            return null;
        }

        if ($rulesExpression instanceof Array_) {
            return $this->rulesFromArrayLiteral($rulesExpression, $method);
        }

        return $this->rulesFromControllerDeclaration($rulesExpression, $method)
            ?? InlineValidationScanResult::degraded(
                'the rules argument is neither an array literal nor a controller-declared $rules property or rules() method',
            );
    }

    // region Call-shape matching

    /** Returns the name of the first `Illuminate\Http\Request`-typed parameter, or null. */
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

    /** Returns the rules argument when the node matches a whitelisted call shape, or null. */
    private function rulesArgumentOf(Node $node, ?string $requestParameterName): ?Expr
    {
        if (
            $node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === 'validate'
            && $node->var instanceof Variable
            && is_string($node->var->name)
            && !$node->isFirstClassCallable()
        ) {
            $arguments = $node->getArgs();

            if ($node->var->name === $requestParameterName && isset($arguments[0])) {
                return $arguments[0]->value;
            }

            // Shape 2: $this->validate($request, [...]), only when argument 0 actually is the
            // request (the typed parameter or a request() helper call); any other first argument
            // makes this an unrelated helper that happens to be named validate().
            if (
                $node->var->name === 'this'
                && isset($arguments[1])
                && $this->isRequestArgument($arguments[0]->value, $requestParameterName)
            ) {
                return $arguments[1]->value;
            }

            return null;
        }

        if (
            $node instanceof StaticCall
            && $node->name instanceof Identifier
            && $node->class instanceof Name
            && !$node->isFirstClassCallable()
        ) {
            $arguments = $node->getArgs();

            if (
                $node->name->toLowerString() === 'make'
                && $this->facadeMatches($node->class, self::VALIDATOR_FACADE, 'Validator')
                && isset($arguments[1])
            ) {
                return $arguments[1]->value;
            }

            if (
                $node->name->toLowerString() === 'validate'
                && $this->facadeMatches($node->class, self::REQUEST_FACADE, 'Request')
                && isset($arguments[0])
            ) {
                return $arguments[0]->value;
            }
        }

        return null;
    }

    /**
     * Whether the first argument is the typed request parameter or a zero-argument `request()`
     * helper call (the Bagisto idiom). An untyped variable is rejected.
     */
    private function isRequestArgument(Expr $argument, ?string $requestParameterName): bool
    {
        if (
            $argument instanceof Variable
            && is_string($argument->name)
            && $requestParameterName !== null
            && $argument->name === $requestParameterName
        ) {
            return true;
        }

        return $argument instanceof FuncCall
            && $argument->name instanceof Name
            && $argument->name->toLowerString() === 'request'
            && !$argument->isFirstClassCallable()
            && $argument->getArgs() === [];
    }

    /**
     * Matches a static-call class against a facade: either the FQCN (after NameResolver) or the
     * bare root-namespace short name (Laravel's global alias). An import bound to a different class
     * never matches.
     */
    private function facadeMatches(Name $class, string $facadeClass, string $shortName): bool
    {
        $resolved = $class->toString();

        return $resolved === $facadeClass || $resolved === $shortName;
    }

    // endregion

    // region Rules-argument resolution

    private function rulesFromArrayLiteral(Array_ $expression, ReflectionMethod $method): InlineValidationScanResult
    {
        $file = $method->getFileName();

        /** @var array<string, array<int, mixed>|string> $rules */
        $rules = [];

        /** @var array<string, string> $descriptions */
        $descriptions = [];

        /** @var list<string> $skippedFields */
        $skippedFields = [];

        foreach ($expression->items as $item) {
            if ($item->unpack || $item->key === null) {
                return InlineValidationScanResult::degraded('the rules array contains a spread or unkeyed entry');
            }

            try {
                $fieldName = AstLiteralEvaluator::evaluate($item->key);
            } catch (NonLiteralValueException) {
                return InlineValidationScanResult::degraded('the rules array has a dynamic key');
            }

            if (!is_string($fieldName)) {
                return InlineValidationScanResult::degraded('the rules array has a non-string key');
            }

            $fieldRules = RuleFieldLiteralMapper::map($item->value);

            if ($fieldRules === null) {
                $skippedFields[] = $fieldName;

                continue;
            }

            $rules[$fieldName] = $fieldRules;

            if ($file !== false) {
                $comment = $this->scanner->trailingCommentAfter($file, $item);

                if ($comment !== null) {
                    $descriptions[$fieldName] = $comment;
                }
            }
        }

        if ($rules === []) {
            return InlineValidationScanResult::degraded('no rule entry could be read as a literal');
        }

        return InlineValidationScanResult::recovered($rules, $descriptions, $skippedFields);
    }

    /**
     * Resolves a `$this->rules` property or zero-argument `$this->rules()` call, optionally
     * subscripted with a literal key. Returns null when the expression is not one of these shapes.
     */
    private function rulesFromControllerDeclaration(
        Expr $expression,
        ReflectionMethod $method,
    ): ?InlineValidationScanResult {
        $subscriptKey = null;

        if ($expression instanceof ArrayDimFetch) {
            if ($expression->dim === null) {
                return null;
            }

            try {
                $subscriptKey = AstLiteralEvaluator::evaluate($expression->dim);
            } catch (NonLiteralValueException) {
                return InlineValidationScanResult::degraded('the $this->rules subscript key is dynamic');
            }

            if (!is_string($subscriptKey) && !is_int($subscriptKey)) {
                return InlineValidationScanResult::degraded(
                    'the $this->rules subscript key is not a string or integer',
                );
            }

            $expression = $expression->var;
        }

        $controller = $method->getDeclaringClass();

        if (
            $expression instanceof PropertyFetch
            && $expression->var instanceof Variable
            && $expression->var->name === 'this'
            && $expression->name instanceof Identifier
        ) {
            $declared = $this->propertyDefault($controller, $expression->name->toString());

            if ($declared === null) {
                return InlineValidationScanResult::degraded(
                    sprintf(
                        'property $%s has no literal array default value',
                        $expression->name->toString(),
                    ),
                );
            }

            return $this->resultFromDeclaredRules($declared, $subscriptKey);
        }

        if (
            $expression instanceof MethodCall
            && $expression->var instanceof Variable
            && $expression->var->name === 'this'
            && $expression->name instanceof Identifier
            && !$expression->isFirstClassCallable()
            && $expression->getArgs() === []
        ) {
            $declared = $this->invokeRulesMethod($controller, $expression->name->toString());

            if ($declared === null) {
                return InlineValidationScanResult::degraded(
                    sprintf(
                        'method %s() could not be invoked or did not return an array',
                        $expression->name->toString(),
                    ),
                );
            }

            return $this->resultFromDeclaredRules($declared, $subscriptKey);
        }

        return null;
    }

    /**
     * @param ReflectionClass<object> $controller
     *
     * @return null|array<int|string, mixed>
     */
    private function propertyDefault(ReflectionClass $controller, string $propertyName): ?array
    {
        try {
            $property = $controller->getProperty($propertyName);
        } catch (ReflectionException) {
            return null;
        }

        if (!$property->hasDefaultValue()) {
            return null;
        }

        $default = $property->getDefaultValue();

        return is_array($default) ? $default : null;
    }

    /**
     * @param array<int|string, mixed> $declared
     */
    private function resultFromDeclaredRules(array $declared, string|int|null $subscriptKey): InlineValidationScanResult
    {
        if ($subscriptKey !== null) {
            if (!array_key_exists($subscriptKey, $declared) || !is_array($declared[$subscriptKey])) {
                return InlineValidationScanResult::degraded(
                    sprintf(
                        'the declared rules have no array entry under key "%s"',
                        $subscriptKey,
                    ),
                );
            }

            /** @var array<int|string, mixed> $declared */
            $declared = $declared[$subscriptKey];
        }

        /** @var array<string, array<int, mixed>|string> $rules */
        $rules = [];

        foreach ($declared as $field => $fieldRules) {
            if (!is_string($field)) {
                continue;
            }

            if (is_string($fieldRules)) {
                $rules[$field] = $fieldRules;
            } elseif (is_array($fieldRules)) {
                $rules[$field] = array_values($fieldRules);
            } else {
                // A bare Rule object: ValidationRulesToSchema accepts it inside a list.
                $rules[$field] = [$fieldRules];
            }
        }

        if ($rules === []) {
            return InlineValidationScanResult::degraded('the declared rules array is empty or has no string keys');
        }

        return InlineValidationScanResult::recovered($rules);
    }

    /**
     * Invokes a zero-argument rules method on an instance created without the constructor.
     * Degrades gracefully if the method throws (missing dependencies, uninitialised state).
     *
     * @param ReflectionClass<object> $controller
     *
     * @return null|array<int|string, mixed>
     */
    private function invokeRulesMethod(ReflectionClass $controller, string $methodName): ?array
    {
        try {
            $rulesMethod = $controller->getMethod($methodName);
        } catch (ReflectionException) {
            return null;
        }

        if ($rulesMethod->getNumberOfRequiredParameters() > 0 || $rulesMethod->isStatic()) {
            return null;
        }

        try {
            $instance = $controller->newInstanceWithoutConstructor();
            $result = $rulesMethod->invoke($instance);
        } catch (Throwable) {
            return null;
        }

        return is_array($result) ? $result : null;
    }

    // endregion
}
