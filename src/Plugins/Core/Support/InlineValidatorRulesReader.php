<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Http\Request;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\NonLiteralValueException;
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
 * Tier-1 whitelist matcher for inline validation rules in a controller method body.
 *
 * Scans the first {@see self::STATEMENT_LIMIT} top-level statements for exactly four call shapes:
 *
 *  1. `$request->validate([...])` — on the method's `Illuminate\Http\Request`-typed parameter
 *  2. `$this->validate($request, [...])`
 *  3. `Validator::make($request->all(), [...])`
 *  4. `Request::validate([...])` (the facade)
 *
 * The rules argument may be an array literal (trailing `//` comments on its entries become field
 * descriptions), or a controller-declared `$this->rules` property / zero-argument `$this->rules()`
 * method — optionally subscripted with a literal key (`$this->rules()['create']`, the BookStack
 * idiom). Anything dynamic degrades to a {@see InlineValidationScanResult::degraded()} result; no
 * variable tracking, no cross-call dataflow (Tier 2 is refused, see epic #5).
 *
 * @internal
 */
#[Scoped]
final readonly class InlineValidatorRulesReader
{
    public const int STATEMENT_LIMIT = 10;

    private const string VALIDATOR_FACADE = 'Illuminate\Support\Facades\Validator';

    private const string REQUEST_FACADE = 'Illuminate\Support\Facades\Request';

    private NodeFinder $nodeFinder;

    public function __construct(
        private MethodBodyScanner $scanner,
    ) {
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * Returns null when no whitelisted validator call is present in the scanned statements; a
     * degraded result when one is present but its rules cannot be read statically.
     */
    public function read(ReflectionMethod $method): ?InlineValidationScanResult
    {
        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);

        if ($statements === []) {
            return null;
        }

        $requestParameterName = $this->requestParameterName($method);
        $rulesExpression = null;

        foreach ($statements as $statement) {
            // Only straight-line statements participate: a validate() inside an `if` branch is
            // conditional, and reading it would be guessing.
            $expression = match (true) {
                $statement instanceof Expression => $statement->expr,
                $statement instanceof Return_ => $statement->expr,
                default => null,
            };

            if ($expression === null) {
                continue;
            }

            $call = $this->nodeFinder->findFirst(
                $expression,
                fn(Node $node): bool => $this->rulesArgumentOf($node, $requestParameterName) instanceof Expr,
            );

            if ($call !== null) {
                $rulesExpression = $this->rulesArgumentOf($call, $requestParameterName);

                break;
            }
        }

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

    /**
     * Returns the rules argument expression when the node is one of the four whitelisted
     * validator call shapes, or null otherwise.
     */
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

            // Shape 1: $request->validate([...])
            if ($node->var->name === $requestParameterName && isset($arguments[0])) {
                return $arguments[0]->value;
            }

            // Shape 2: $this->validate($request, [...])
            if ($node->var->name === 'this' && isset($arguments[1])) {
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

            // Shape 3: Validator::make($request->all(), [...])
            if (
                $node->name->toLowerString() === 'make'
                && $this->facadeMatches($node->class, self::VALIDATOR_FACADE, 'Validator')
                && isset($arguments[1])
            ) {
                return $arguments[1]->value;
            }

            // Shape 4: Request::validate([...])
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
     * Matches a static-call class name against a facade: either it resolves to the facade FQCN
     * (via import or alias), or it was written as the bare short name in a namespace where it
     * does not resolve to anything else.
     */
    private function facadeMatches(Name $class, string $facadeClass, string $shortName): bool
    {
        if ($class->toString() === $facadeClass) {
            return true;
        }

        $originalName = $class->getAttribute('originalName');
        $writtenName = $originalName instanceof Name ? $originalName->toString() : $class->toString();

        return $writtenName === $shortName;
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

            $fieldRules = $this->fieldRulesOf($item->value);

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
     * Evaluates one field's ruleset. A literal string (pipe syntax) passes through; an array
     * keeps its literal elements and drops dynamic ones (e.g. `Rule::unique(...)`) — they refine
     * validation, not the schema shape. A fully dynamic value returns null (field skipped).
     *
     * @return null|array<int, mixed>|string
     */
    private function fieldRulesOf(Expr $value): string|array|null
    {
        if (!$value instanceof Array_) {
            try {
                $evaluated = AstLiteralEvaluator::evaluate($value);
            } catch (NonLiteralValueException) {
                return null;
            }

            return is_string($evaluated) ? $evaluated : null;
        }

        $elements = [];

        foreach ($value->items as $item) {
            if ($item->unpack || $item->key !== null) {
                return null;
            }

            try {
                $elements[] = AstLiteralEvaluator::evaluate($item->value);
            } catch (NonLiteralValueException) {
                // Dynamic element (a Rule object, a call) — keep the literal rest of the list.
                continue;
            }
        }

        return $elements;
    }

    /**
     * Resolves a `$this->rules` property fetch or zero-argument `$this->rules()` call —
     * optionally subscripted with a literal key — against the controller class via reflection.
     * Returns null when the expression is not one of these shapes (the caller emits the generic
     * degrade reason).
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
                return InlineValidationScanResult::degraded('the $this->rules subscript key is not a string or integer');
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
                return InlineValidationScanResult::degraded(sprintf(
                    'property $%s has no literal array default value',
                    $expression->name->toString(),
                ));
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
                return InlineValidationScanResult::degraded(sprintf(
                    'method %s() could not be invoked or did not return an array',
                    $expression->name->toString(),
                ));
            }

            return $this->resultFromDeclaredRules($declared, $subscriptKey);
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $declared
     */
    private function resultFromDeclaredRules(array $declared, string|int|null $subscriptKey): InlineValidationScanResult
    {
        if ($subscriptKey !== null) {
            if (!array_key_exists($subscriptKey, $declared) || !is_array($declared[$subscriptKey])) {
                return InlineValidationScanResult::degraded(sprintf(
                    'the declared rules have no array entry under key "%s"',
                    $subscriptKey,
                ));
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
                // A bare Rule object — ValidationRulesToSchema accepts it inside a list.
                $rules[$field] = [$fieldRules];
            }
        }

        if ($rules === []) {
            return InlineValidationScanResult::degraded('the declared rules array is empty or has no string keys');
        }

        return InlineValidationScanResult::recovered($rules);
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
     * Invokes a zero-argument rules method on an instance created without running the
     * constructor. Userland code — anything it throws (missing dependencies, uninitialised
     * state) degrades rather than aborting the run.
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
