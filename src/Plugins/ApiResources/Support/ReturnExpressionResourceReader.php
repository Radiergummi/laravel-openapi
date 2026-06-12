<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Support;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Attributes\UseResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure as ClosureExpression;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_ as NewExpression;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Return_;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Routing\ResourceTarget;
use Radiergummi\OpenApi\Support\MethodBody\ConditionalContextPolicy;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\StatementNodeFinder;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;

use function array_key_exists;
use function class_exists;
use function count;
use function in_array;
use function is_a;
use function is_array;
use function is_string;
use function method_exists;
use function sprintf;

/**
 * Resolves the concrete API Resource (or wrapped Eloquent model) an action returns when its
 * signature names only a base resource type — a Tier-1 bounded scan of the method's return
 * expression (epic #5, issue #108), consulted by {@see ResourceClassLocator} on a Tier-0 miss.
 *
 * The method's `@return` docblock generic (`AnonymousResourceCollection<FooResource>`) is read
 * first and wins over the body — it is explicit authoring and needs no source parse. The body
 * scan then matches a narrow whitelist against the first {@see self::STATEMENT_LIMIT} top-level
 * statements: the single unconditional `return` expression (or the variable it names, when that
 * variable is assigned exactly once on the unconditional path), unwrapped through
 * resource-preserving chain links (`->additional(...)`). Recognised shapes:
 *
 * - `X::collection(...)` → collection of `X`; `X::make(...)` / `new X(...)` → single `X`,
 *   where `X` resolves (via the scanner's NameResolver pass) to a concrete `JsonResource`
 *   subclass that is not itself a `ResourceCollection`.
 * - `->toResource(X::class)` / `->toResourceCollection(X::class)` — the literal class argument
 *   is decisive, the receiver's type is irrelevant to the schema.
 * - Bare `$model->toResource()` where `$model` is a Model-typed method parameter — the resource
 *   resolves through Laravel's own convention (`#[UseResource]`, then `guessResourceName()`).
 * - `new JsonResource($model)` (exactly the base class) wrapping a Model-typed parameter →
 *   a wrapped-model target; the response documents the model's schema.
 *
 * A collection only claims the paginated `{data, links, meta}` envelope when its argument (or
 * the `toResourceCollection()` receiver) visibly ends in a `paginate()`-family call, looking
 * through paginator-preserving chain links (`->withQueryString()` et al.); otherwise the plain
 * `{data}` envelope is documented — pagination meta is never guessed.
 *
 * Anything else — conditional returns, unknown variables, unrecognised chain links, receivers
 * needing dataflow — refuses with one generation-log NOTICE per action and run
 * (`#[ResponseResource]` is the escape hatch); resolution results are memoised per method.
 *
 * @internal
 */
#[Scoped]
final class ReturnExpressionResourceReader
{
    public const int STATEMENT_LIMIT = 10;

    /**
     * Chained methods (lowercased) that change neither the resource class nor the response
     * cardinality — `->additional(...)` only adds sibling envelope keys (not modeled). Any other
     * chain link may transform the response, so it refuses the scan.
     */
    private const array RESOURCE_PRESERVING_CHAIN_METHODS = ['additional'];

    /**
     * Method names (lowercased) whose call marks a collection source as paginated. All three
     * envelope variants document as the dominant `{data, links, meta}` length-aware shape; its
     * envelope properties are optional, so the simple/cursor variants' narrower meta stays valid.
     */
    private const array PAGINATING_METHODS = ['paginate', 'simplepaginate', 'cursorpaginate'];

    /**
     * Chained methods (lowercased) on a paginator that return `$this` without changing the
     * paginated nature or the item shape — URL/metadata tweaks only — so pagination evidence
     * looks through them (`paginate(...)->withQueryString()` is still paginated). Item-mapping
     * chains (`through()`) are deliberately absent: they may change what each item looks like.
     * Any other trailing call hides the evidence and falls back to the plain `{data}` envelope.
     */
    private const array PAGINATOR_PRESERVING_CHAIN_METHODS = [
        'appends',
        'fragment',
        'withpath',
        'withquerystring',
    ];

    /**
     * Memorized resolution per `Class::method`, so repeated lookups (generation and lint rules)
     * parse once, and the refusal note fires once per run.
     *
     * @var array<string, ?ResourceTarget>
     */
    private array $cache = [];

    private readonly StatementNodeFinder $statementNodeFinder;

    public function __construct(
        private readonly MethodBodyScanner $scanner,
        private readonly DocBlockParser $docBlockParser,
        private readonly TypeNodeResolver $typeNodeResolver,
        private readonly LoggerInterface $logger,
    ) {
        $this->statementNodeFinder = new StatementNodeFinder();
    }

    public static function create(?LoggerInterface $logger = null): self
    {
        return new self(
            scanner: new MethodBodyScanner(),
            docBlockParser: DocBlockParser::create(),
            typeNodeResolver: TypeNodeResolver::create(),
            logger: $logger ?? new NullLogger(),
        );
    }

    /**
     * @throws ReflectionException
     */
    public function read(ReflectionMethod $method): ?ResourceTarget
    {
        $key = $method->getDeclaringClass()->getName() . '::' . $method->getName();

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->resolve($method);
    }

    /**
     * @throws ReflectionException
     */
    private function resolve(ReflectionMethod $method): ?ResourceTarget
    {
        $documented = $this->targetFromReturnTag($method);

        if ($documented !== null) {
            return $documented;
        }

        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);
        $returnExpression = $this->canonicalReturnExpression($statements, $method);

        if ($returnExpression === null) {
            return null;
        }

        $expression = $returnExpression instanceof Variable
            ? $this->expressionAssignedTo($returnExpression, $statements, $method)
            : $returnExpression;

        if ($expression === null) {
            return null;
        }

        return $this->targetFromExpression($expression, $method);
    }

    // region @return docblock generic

    // endregion

    // region Return-expression location

    /**
     * The collection target documented by a `return …Collection<FooResource>` generic on the method
     * docblock, or null when there is none. No body information is available here, so the
     * collection keeps the default paginated envelope.
     */
    private function targetFromReturnTag(ReflectionMethod $method): ?ResourceTarget
    {
        $docComment = $method->getDocComment();

        if ($docComment === false) {
            return null;
        }

        $returnType = $this->docBlockParser->parse($docComment)->returnType();

        if ($returnType === null) {
            return null;
        }

        $resourceClass = $this->concreteResourceClass(
            $this->typeNodeResolver->genericValueClass($returnType, $method),
        );

        if ($resourceClass === null) {
            return null;
        }

        return new ResourceTarget($resourceClass, isCollection: true);
    }

    /**
     * Validates a candidate name as a concrete resource: an existing, non-abstract *proper*
     * `JsonResource` subclass that is not a `ResourceCollection` (a collection class names no
     * item resource). The base `JsonResource` and abstract subclasses carry no field shape, so
     * accepting them would silently document an empty schema where refusing keeps the
     * `resource.response-ambiguous` signal alive.
     *
     * @return null|class-string<JsonResource>
     */
    private function concreteResourceClass(?string $candidate): ?string
    {
        if (
            $candidate === null
            || $candidate === JsonResource::class
            || !class_exists($candidate)
            || !is_a($candidate, JsonResource::class, allow_string: true)
            || is_a($candidate, ResourceCollection::class, allow_string: true)
            || new ReflectionClass($candidate)->isAbstract()
        ) {
            return null;
        }

        /** @var class-string<JsonResource> $candidate */
        return $candidate;
    }

    /**
     * The expression of the method's single unconditional return, or null (with a note) when the
     * scanned region has no top-level return or carries additional returns (conditional or
     * dead-code) — the resource type would be a guess.
     *
     * @param list<Stmt> $statements
     */
    private function canonicalReturnExpression(array $statements, ReflectionMethod $method): ?Expr
    {
        $topLevelReturn = null;

        foreach ($statements as $statement) {
            if ($statement instanceof Return_) {
                $topLevelReturn = $statement;

                break;
            }
        }

        if ($topLevelReturn === null || $topLevelReturn->expr === null) {
            $this->note(
                $method,
                'has no unconditional top-level return in the scanned statements',
            );

            return null;
        }

        if (count($this->methodLevelReturns($statements)) > 1) {
            $this->note(
                $method,
                'is not the method\'s only return, so the resource type would be a guess',
            );

            return null;
        }

        return $topLevelReturn->expr;
    }

    private function note(ReflectionMethod $method, string $reason): void
    {
        $this->logger->notice(
            sprintf(
                'The return expression of %s::%s %s; the concrete resource stays unresolved.'
                . ' Annotate the action with #[ResponseResource] to document the response.',
                $method->getDeclaringClass()->getName(),
                $method->getName(),
                $reason,
            ),
        );
    }

    /**
     * Every return statement belonging to the *method itself* within the scanned statements —
     * returns inside closures, arrow functions, or anonymous classes open their own scope and
     * are excluded.
     *
     * @param list<Stmt> $statements
     *
     * @return list<Return_>
     */
    private function methodLevelReturns(array $statements): array
    {
        $found = [];

        foreach ($statements as $statement) {
            $this->collectMethodLevelReturns($statement, $found);
        }

        return $found;
    }

    // endregion

    // region Call-shape matching

    /**
     * @param list<Return_> $found
     */
    private function collectMethodLevelReturns(Node $node, array &$found): void
    {
        if (
            $node instanceof ClosureExpression
            || $node instanceof ArrowFunction
            || $node instanceof ClassLike
        ) {
            return;
        }

        if ($node instanceof Return_) {
            $found[] = $node;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            /** @var mixed $children */
            $children = $node->{$subNodeName};

            foreach (is_array($children) ? $children : [$children] as $child) {
                if ($child instanceof Node) {
                    $this->collectMethodLevelReturns($child, $found);
                }
            }
        }
    }

    /**
     * Resolves `return $variable;` through the single assignment to that variable — the
     * two-statement `$x = X::collection(...); return $x;` form. The assignment must be the only
     * one targeting the variable anywhere in the scanned region (a conditional reassignment makes
     * the type a guess) and must itself sit on the unconditional path.
     *
     * @param list<Stmt> $statements
     */
    private function expressionAssignedTo(
        Variable $variable,
        array $statements,
        ReflectionMethod $method,
    ): ?Expr {
        $variableName = $variable->name;

        if (!is_string($variableName)) {
            $this->note($method, 'returns a dynamically-named variable');

            return null;
        }

        $isAssignmentToVariable = static fn(Node $node): bool
            => $node instanceof Assign
            && $node->var instanceof Variable
            && $node->var->name === $variableName;

        $allAssignments = $this->statementNodeFinder->findAll(
            $statements,
            ConditionalContextPolicy::IncludeConditionalContexts,
            $isAssignmentToVariable,
        );
        $unconditionalAssignments = $this->statementNodeFinder->findAll(
            $statements,
            ConditionalContextPolicy::SkipConditionalContexts,
            $isAssignmentToVariable,
        );

        if (count($allAssignments) !== 1 || count($unconditionalAssignments) !== 1) {
            $this->note(
                $method,
                sprintf(
                    'returns $%s, which is not assigned exactly once on the unconditional path',
                    $variableName,
                ),
            );

            return null;
        }

        /** @var Assign $assignment */
        $assignment = $unconditionalAssignments[0];

        return $assignment->expr;
    }

    /**
     * Matches the return expression against the whitelisted shapes, unwrapping
     * resource-preserving chain links first. Every refusal path notes its reason.
     *
     * @throws ReflectionException
     */
    private function targetFromExpression(
        Expr $expression,
        ReflectionMethod $method,
    ): ?ResourceTarget {
        $current = $expression;

        while (true) {
            if ($current instanceof StaticCall) {
                return $this->targetFromStaticCall($current, $method);
            }

            if ($current instanceof NewExpression) {
                return $this->targetFromNewExpression($current, $method);
            }

            if ($current instanceof MethodCall && $current->name instanceof Identifier) {
                $methodName = $current->name->toLowerString();

                if (in_array(
                    $methodName,
                    self::RESOURCE_PRESERVING_CHAIN_METHODS,
                    true,
                )) {
                    $current = $current->var;

                    continue;
                }

                if ($methodName === 'toresource' || $methodName === 'toresourcecollection') {
                    return $this->targetFromTransformCall(
                        $current,
                        $methodName,
                        $method,
                    );
                }

                $this->note(
                    $method,
                    sprintf(
                        'is chained into ->%s(), which the scan does not recognise',
                        $current->name->toString(),
                    ),
                );

                return null;
            }

            $this->note(
                $method,
                'does not match a recognised resource-returning shape '
                . '(X::collection(...), X::make(...), new X(...), $model->toResource())',
            );

            return null;
        }
    }

    /**
     * `X::collection(...)` → collection of `X`; `X::make(...)` → single `X`.
     */
    private function targetFromStaticCall(
        StaticCall $call,
        ReflectionMethod $method,
    ): ?ResourceTarget {
        $resourceClass = $call->class instanceof Name
            ? $this->concreteResourceClass($call->class->toString())
            : null;
        $methodName = $call->name instanceof Identifier ? $call->name->toLowerString() : null;

        if (
            $resourceClass === null
            || !in_array($methodName, ['collection', 'make'], true)
        ) {
            $this->note(
                $method,
                'is a static call that does not name a concrete JsonResource subclass',
            );

            return null;
        }

        if ($methodName === 'collection') {
            $argument = $call->getArgs()[0]->value ?? null;

            return new ResourceTarget(
                $resourceClass,
                isCollection: true,
                paginated: $argument !== null && $this->endsInPaginatingCall($argument),
            );
        }

        return new ResourceTarget($resourceClass, isCollection: false);
    }

    // endregion

    // region Class & type resolution

    /**
     * Whether the expression's outermost call is a `paginate()`-family method.
     */
    private function endsInPaginatingCall(Expr $expression): bool
    {
        while (
            $expression instanceof MethodCall
            && $expression->name instanceof Identifier
            && in_array(
                $expression->name->toLowerString(),
                self::PAGINATOR_PRESERVING_CHAIN_METHODS,
                true,
            )
        ) {
            $expression = $expression->var;
        }

        $name = match (true) {
            $expression instanceof MethodCall => $expression->name,
            $expression instanceof StaticCall => $expression->name,
            default => null,
        };

        return $name instanceof Identifier
            && in_array($name->toLowerString(), self::PAGINATING_METHODS, true);
    }

    /**
     * `new X(...)` → single `X`; `new JsonResource($model)` (exactly the base class) wrapping a
     * Model-typed parameter → wrapped-model target.
     */
    private function targetFromNewExpression(
        NewExpression $new,
        ReflectionMethod $method,
    ): ?ResourceTarget {
        if (!$new->class instanceof Name) {
            $this->note($method, 'instantiates a dynamically-resolved class');

            return null;
        }

        $className = $new->class->toString();

        if ($className === JsonResource::class) {
            $argument = $new->getArgs()[0]->value ?? null;
            $modelClass = $argument instanceof Variable && is_string($argument->name)
                ? $this->parameterModelClass($method, $argument->name)
                : null;

            if ($modelClass === null) {
                $this->note(
                    $method,
                    'wraps a value of unknown model type in the base JsonResource',
                );

                return null;
            }

            return new ResourceTarget(
                resourceClass: null,
                isCollection: false,
                modelClass: $modelClass,
            );
        }

        $resourceClass = $this->concreteResourceClass($className);

        if ($resourceClass === null) {
            $this->note(
                $method,
                'instantiates a class that is not a concrete JsonResource subclass',
            );

            return null;
        }

        return new ResourceTarget($resourceClass, isCollection: false);
    }

    /**
     * The Model subclass a method parameter of the given name, is typed with or null.
     *
     * @return null|class-string<Model>
     */
    private function parameterModelClass(ReflectionMethod $method, string $parameterName): ?string
    {
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getName() !== $parameterName) {
                continue;
            }

            $type = $parameter->getType();

            if (
                $type instanceof ReflectionNamedType
                && !$type->isBuiltin()
                && is_a($type->getName(), Model::class, allow_string: true)
            ) {
                /** @var class-string<Model> $modelClass */
                $modelClass = $type->getName();

                return $modelClass;
            }

            return null;
        }

        return null;
    }

    /**
     * `->toResource(X::class)` / `->toResourceCollection(X::class)` — the literal class argument
     * is decisive. A bare `$model->toResource()` resolves Laravel's conventional resource for a
     * Model-typed parameter receiver; every other receiver would need dataflow and refuses.
     *
     * @throws ReflectionException
     */
    private function targetFromTransformCall(
        MethodCall $call,
        string $methodName,
        ReflectionMethod $method,
    ): ?ResourceTarget {
        $arguments = $call->getArgs();

        if ($arguments !== []) {
            $resourceClass = $this->resourceClassFromArgument($arguments[0]->value);

            if ($resourceClass === null) {
                $this->note(
                    $method,
                    sprintf(
                        'passes a non-literal or non-resource class to ->%s()',
                        $call->name instanceof Identifier ? $call->name->toString() : $methodName,
                    ),
                );

                return null;
            }

            if ($methodName === 'toresourcecollection') {
                return new ResourceTarget(
                    $resourceClass,
                    isCollection: true,
                    paginated: $this->endsInPaginatingCall($call->var),
                );
            }

            return new ResourceTarget($resourceClass, isCollection: false);
        }

        if ($methodName === 'toresourcecollection') {
            $this->note($method, 'calls ->toResourceCollection() without naming the resource class');

            return null;
        }

        $modelClass = $call->var instanceof Variable && is_string($call->var->name)
            ? $this->parameterModelClass($method, $call->var->name)
            : null;

        if ($modelClass === null) {
            $this->note($method, 'calls ->toResource() on a receiver whose model type is not statically knowable');

            return null;
        }

        $resourceClass = $this->conventionalResourceFor($modelClass);

        if ($resourceClass === null) {
            $this->note(
                $method,
                sprintf(
                    'calls ->toResource() on %s, but no conventional resource class could be resolved for it',
                    $modelClass,
                ),
            );

            return null;
        }

        return new ResourceTarget($resourceClass, isCollection: false);
    }

    /**
     * The concrete resource named by a literal `X::class` argument, or null for anything else.
     *
     * @return null|class-string<JsonResource>
     */
    private function resourceClassFromArgument(Expr $argument): ?string
    {
        if (
            !$argument instanceof ClassConstFetch
            || !$argument->class instanceof Name
            || !$argument->name instanceof Identifier
            || $argument->name->toLowerString() !== 'class'
        ) {
            return null;
        }

        return $this->concreteResourceClass($argument->class->toString());
    }

    // endregion

    /**
     * The resource class a bare `$model->toResource()` serializes through, mirroring Laravel's
     * own resolution: the model's `#[UseResource]` attribute first, then the
     * `guessResourceName()` namespace convention.
     *
     * @param class-string<Model> $modelClass
     *
     * @return null|class-string<JsonResource>
     *
     * @throws ReflectionException
     */
    private function conventionalResourceFor(string $modelClass): ?string
    {
        if (class_exists(UseResource::class)) {
            $attribute = new ReflectionClass($modelClass)->getAttributes(UseResource::class)[0] ?? null;

            if ($attribute !== null) {
                $resourceClass = $this->concreteResourceClass($attribute->newInstance()->class);

                if ($resourceClass !== null) {
                    return $resourceClass;
                }
            }
        }

        if (!method_exists($modelClass, 'guessResourceName')) {
            return null;
        }

        foreach ($modelClass::guessResourceName() as $candidate) {
            $resourceClass = is_string($candidate) ? $this->concreteResourceClass($candidate) : null;

            if ($resourceClass !== null) {
                return $resourceClass;
            }
        }

        return null;
    }
}
