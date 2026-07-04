<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Support;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Attributes\UseResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_ as NewExpression;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Return_;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Enums\PaginatorKind;
use Radiergummi\OpenApi\Routing\ResourceTarget;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\ResponseJsonCall;
use Radiergummi\OpenApi\Support\MethodBody\ReturnExpressionResolver;
use Radiergummi\OpenApi\Support\MethodBody\ReturnVariableRefusal;
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
use function is_string;
use function method_exists;
use function sprintf;

/**
 * Resolves the concrete API Resource an action returns when its signature names only a base type.
 * Consulted by {@see ResourceClassLocator} when the return type alone is insufficient.
 *
 * The `@return` generic wins over the body scan. The scan then matches a narrow whitelist in the
 * first {@see self::STATEMENT_LIMIT} statements: one unconditional return (or the variable it
 * names, assigned exactly once on the unconditional path), or several top-level returns that all
 * resolve to the same resource (bare `return;` / `return null;` ignored), unwrapped through
 * resource-preserving chains (`->additional(...)`) and out of a `response()->json(<resource>, …)`
 * wrapper. Recognised shapes: `X::collection(...)`, `X::collect(...)`,
 * `X::make(...)`, `new X(...)`, `->toResource(X::class)`, `->toResourceCollection(X::class)`, bare
 * `$model->toResource()` on a Model-typed parameter, and `new JsonResource($model)` wrapping one.
 *
 * Pagination evidence is derived from the argument/receiver ending in a `paginate()`-family call.
 * Anything else refuses with one NOTICE per action and run; `#[ResponseResource]` is the escape
 * hatch. Results are memoised per method.
 *
 * @internal
 */
#[Scoped]
final class ReturnExpressionResourceReader
{
    public const int STATEMENT_LIMIT = 10;

    /**
     * Chain methods that change neither the resource class nor the response cardinality.
     * Any other link may transform the response, so the scan refuses it.
     */
    private const array RESOURCE_PRESERVING_CHAIN_METHODS = ['additional'];

    /**
     * Paginator chain methods that don't change the paginated nature or item shape (URL/metadata
     * tweaks only). Item-mapping chains (`through()`) are absent: they may change item shape.
     * Any other trailing call falls back to the plain `{data}` envelope.
     */
    private const array PAGINATOR_PRESERVING_CHAIN_METHODS = [
        'appends',
        'fragment',
        'withpath',
        'withquerystring',
    ];

    /**
     * Memoised per `Class::method`: parses once, fires the refusal note once per run.
     *
     * @var array<string, ?ResourceTarget>
     */
    private array $cache = [];

    public function __construct(
        private readonly MethodBodyScanner $scanner,
        private readonly DocBlockParser $docBlockParser,
        private readonly TypeNodeResolver $typeNodeResolver,
        private readonly LoggerInterface $logger,
        private readonly ReturnExpressionResolver $returnExpressionResolver = new ReturnExpressionResolver(),
    ) {}

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
     * @param bool $silent Suppress the refusal notice (untyped path: the scan runs on every action,
     *                     most of which are not resources). Never affects the resolved target.
     *
     * @throws ReflectionException
     */
    public function read(ReflectionMethod $method, bool $silent = false): ?ResourceTarget
    {
        $key = $method->getDeclaringClass()->getName() . '::' . $method->getName();

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->resolve($method, $silent);
    }

    /**
     * @throws ReflectionException
     */
    private function resolve(ReflectionMethod $method, bool $silent): ?ResourceTarget
    {
        $documented = $this->targetFromReturnTag($method);

        if ($documented !== null) {
            return $documented;
        }

        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);

        if (count($this->methodLevelReturns($statements)) > 1) {
            return $this->reconcileMultipleReturns($statements, $method, $silent);
        }

        $returnExpression = $this->canonicalReturnExpression($statements, $method, log: !$silent);

        if ($returnExpression === null) {
            return null;
        }

        $expression = $returnExpression instanceof Variable
            ? $this->expressionAssignedTo($returnExpression, $statements, $method, log: !$silent)
            : $returnExpression;

        if ($expression === null) {
            return null;
        }

        return $this->targetFromExpression($expression, $method, $silent);
    }

    /**
     * Resolves a method with several top-level returns by reconciling every resource-bearing
     * branch through the single-return path. Emits the target only when all branches agree;
     * bare `return;` and `return null;` are ignored sentinels, and any unresolvable or divergent
     * branch (or no resource branch at all) degrades to null.
     *
     * @param list<Stmt> $statements
     *
     * @throws ReflectionException
     */
    private function reconcileMultipleReturns(
        array $statements,
        ReflectionMethod $method,
        bool $silent,
    ): ?ResourceTarget {
        $resolved = [];

        foreach ($this->methodLevelReturns($statements) as $return) {
            $expression = $return->expr;

            if ($expression === null || $this->isNullLiteral($expression)) {
                continue;
            }

            if ($expression instanceof Variable) {
                $expression = $this->expressionAssignedTo($expression, $statements, $method, log: false);
            }

            $target = $expression !== null
                ? $this->targetFromExpression($expression, $method, silent: true)
                : null;

            if ($target === null) {
                $this->note(
                    $method,
                    'has a return path that does not resolve to a resource type',
                    $silent,
                );

                return null;
            }

            $resolved[] = $target;
        }

        if ($resolved === []) {
            return null;
        }

        $first = $resolved[0];

        foreach ($resolved as $target) {
            if (!$this->targetsMatch($first, $target)) {
                $this->note(
                    $method,
                    'has multiple returns resolving to different resource types',
                    $silent,
                );

                return null;
            }
        }

        return $first;
    }

    /**
     * Two targets are the same iff all four fields match.
     */
    private function targetsMatch(ResourceTarget $first, ResourceTarget $second): bool
    {
        return $first->resourceClass === $second->resourceClass
            && $first->isCollection === $second->isCollection
            && $first->modelClass === $second->modelClass
            && $first->paginated === $second->paginated;
    }

    /**
     * Whether the expression is the `null` literal (`return null;`), a non-resource sentinel.
     */
    private function isNullLiteral(Expr $expression): bool
    {
        return $expression instanceof ConstFetch
            && $expression->name->toLowerString() === 'null';
    }

    // region Return-expression location

    /**
     * The collection target from a `@return …Collection<FooResource>` generic, or null.
     * Pagination evidence is derived from the body; falls back to non-paginated when unavailable.
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

        return new ResourceTarget(
            $resourceClass,
            isCollection: true,
            paginated: $this->paginatedFromBody($method),
        );
    }

    /**
     * Returns the candidate only when it is an existing, non-abstract `JsonResource` subclass
     * that is not a `ResourceCollection`. Rejects the base class and abstract subclasses
     * (no field shape) to keep the `resource.response-ambiguous` signal alive.
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
     * `true` only when the single unconditional return is a collection shape whose source ends
     * in a `paginate()`-family call. Never logs: on the docblock path, refusal notices are noise.
     */
    private function paginatedFromBody(ReflectionMethod $method): bool
    {
        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);
        $returnExpression = $this->canonicalReturnExpression($statements, $method, log: false);

        if ($returnExpression === null) {
            return false;
        }

        $expression = $returnExpression instanceof Variable
            ? $this->expressionAssignedTo($returnExpression, $statements, $method, log: false)
            : $returnExpression;

        if ($expression === null) {
            return false;
        }

        while (
            $expression instanceof MethodCall
            && $expression->name instanceof Identifier
            && in_array($expression->name->toLowerString(), self::RESOURCE_PRESERVING_CHAIN_METHODS, true)
        ) {
            $expression = $expression->var;
        }

        if (
            $expression instanceof StaticCall
            && $expression->name instanceof Identifier
            && in_array($expression->name->toLowerString(), ['collection', 'collect'], true)
        ) {
            $argument = $expression->getArgs()[0]->value ?? null;

            return $argument !== null && $this->endsInPaginatingCall($argument);
        }

        if (
            $expression instanceof MethodCall
            && $expression->name instanceof Identifier
            && $expression->name->toLowerString() === 'toresourcecollection'
            && $expression->getArgs() !== []
        ) {
            return $this->endsInPaginatingCall($expression->var);
        }

        return false;
    }

    /**
     * The single unconditional top-level return expression, or null. Additional returns make the
     * resource type a guess. Pass `log: false` to suppress the notice (e.g., pagination probe).
     *
     * @param list<Stmt> $statements
     */
    private function canonicalReturnExpression(
        array $statements,
        ReflectionMethod $method,
        bool $log = true,
    ): ?Expr {
        $topLevelReturn = null;

        foreach ($statements as $statement) {
            if ($statement instanceof Return_) {
                $topLevelReturn = $statement;

                break;
            }
        }

        if ($topLevelReturn === null || $topLevelReturn->expr === null) {
            if ($log) {
                $this->note(
                    $method,
                    'has no unconditional top-level return in the scanned statements',
                );
            }

            return null;
        }

        if (count($this->methodLevelReturns($statements)) > 1) {
            if ($log) {
                $this->note(
                    $method,
                    'is not the method\'s only return, so the resource type would be a guess',
                );
            }

            return null;
        }

        return $topLevelReturn->expr;
    }

    private function note(ReflectionMethod $method, string $reason, bool $silent = false): void
    {
        if ($silent) {
            return;
        }

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
     * Returns belonging to the method itself; closures, arrow functions, and anonymous classes
     * open their own scope and are excluded.
     *
     * @param list<Stmt> $statements
     *
     * @return list<Return_>
     */
    private function methodLevelReturns(array $statements): array
    {
        return $this->returnExpressionResolver->methodLevelReturns($statements);
    }

    // endregion

    // region Call-shape matching

    /**
     * Resolves `return $variable;` through the single unconditional assignment to that variable,
     * refused when the variable is dynamically named, not assigned exactly once on the
     * unconditional path, or mutated after its assignment. Pass `log: false` to suppress the notice.
     *
     * @param list<Stmt> $statements
     */
    private function expressionAssignedTo(
        Variable $variable,
        array $statements,
        ReflectionMethod $method,
        bool $log = true,
    ): ?Expr {
        $resolution = $this->returnExpressionResolver->resolveVariable($variable, $statements);

        if ($resolution->expression !== null) {
            return $resolution->expression;
        }

        if ($log) {
            $this->note($method, $this->refusalReason($resolution->refusal, $variable));
        }

        return null;
    }

    /**
     * The existing per-refusal notice wording, preserved verbatim so the log surface is unchanged.
     */
    private function refusalReason(?ReturnVariableRefusal $refusal, Variable $variable): string
    {
        return match ($refusal) {
            ReturnVariableRefusal::DynamicallyNamedVariable => 'returns a dynamically-named variable',
            ReturnVariableRefusal::MutatedAfterAssignment => sprintf(
                'returns $%s, which is mutated after its single unconditional assignment',
                is_string($variable->name) ? $variable->name : '{dynamic}',
            ),
            default => sprintf(
                'returns $%s, which is not assigned exactly once on the unconditional path',
                is_string($variable->name) ? $variable->name : '{dynamic}',
            ),
        };
    }

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
            && PaginatorKind::fromPaginatingMethod($name->toString()) !== null;
    }

    /**
     * Matches the expression against whitelisted shapes, unwrapping resource-preserving chains.
     *
     * @throws ReflectionException
     */
    private function targetFromExpression(
        Expr $expression,
        ReflectionMethod $method,
        bool $silent,
    ): ?ResourceTarget {
        $current = $expression;

        while (true) {
            // `response()->json(<resource>, <status>)`: the status is Core's concern, the resource
            // is ours, so unwrap to the data argument and resolve it as any other resource shape.
            $jsonData = ResponseJsonCall::dataArgument($current);

            if ($jsonData !== null) {
                $current = $jsonData->value;

                continue;
            }

            if ($current instanceof StaticCall) {
                return $this->targetFromStaticCall($current, $method, $silent);
            }

            if ($current instanceof NewExpression) {
                return $this->targetFromNewExpression($current, $method, $silent);
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
                        $silent,
                    );
                }

                $this->note(
                    $method,
                    sprintf(
                        'is chained into ->%s(), which the scan does not recognise',
                        $current->name->toString(),
                    ),
                    $silent,
                );

                return null;
            }

            $this->note(
                $method,
                'does not match a recognised resource-returning shape '
                . '(X::collection(...), X::collect(...), X::make(...), new X(...), '
                . '$model->toResource())',
                $silent,
            );

            return null;
        }
    }

    // endregion

    // region Class & type resolution

    /**
     * `X::collection(...)` / `X::collect(...)` → collection of `X`; `X::make(...)` → single `X`.
     */
    private function targetFromStaticCall(
        StaticCall $call,
        ReflectionMethod $method,
        bool $silent,
    ): ?ResourceTarget {
        $resourceClass = $call->class instanceof Name
            ? $this->concreteResourceClass($call->class->toString())
            : null;
        $methodName = $call->name instanceof Identifier ? $call->name->toLowerString() : null;

        if (
            $resourceClass === null
            || !in_array($methodName, ['collection', 'collect', 'make'], true)
        ) {
            $this->note(
                $method,
                'is a static call that does not name a concrete JsonResource subclass',
                $silent,
            );

            return null;
        }

        if (in_array($methodName, ['collection', 'collect'], true)) {
            $argument = $call->getArgs()[0]->value ?? null;

            return new ResourceTarget(
                $resourceClass,
                isCollection: true,
                paginated: $argument !== null && $this->endsInPaginatingCall($argument),
            );
        }

        return new ResourceTarget($resourceClass, isCollection: false);
    }

    /**
     * `new X(...)` → single `X`. `new JsonResource($model)` with a Model-typed parameter →
     * wrapped-model target.
     */
    private function targetFromNewExpression(
        NewExpression $new,
        ReflectionMethod $method,
        bool $silent,
    ): ?ResourceTarget {
        if (!$new->class instanceof Name) {
            $this->note($method, 'instantiates a dynamically-resolved class', $silent);

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
                    $silent,
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
                $silent,
            );

            return null;
        }

        return new ResourceTarget($resourceClass, isCollection: false);
    }

    /**
     * The Model subclass that the named parameter is typed with, or null.
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
     * Handles `->toResource(X::class)` / `->toResourceCollection(X::class)` and bare
     * `$model->toResource()` on a Model-typed parameter. Other receivers need dataflow and refuse.
     *
     * @throws ReflectionException
     */
    private function targetFromTransformCall(
        MethodCall $call,
        string $methodName,
        ReflectionMethod $method,
        bool $silent,
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
                    $silent,
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
            $this->note(
                $method,
                'calls ->toResourceCollection() without naming the resource class',
                $silent,
            );

            return null;
        }

        $modelClass = $call->var instanceof Variable && is_string($call->var->name)
            ? $this->parameterModelClass($method, $call->var->name)
            : null;

        if ($modelClass === null) {
            $this->note(
                $method,
                'calls ->toResource() on a receiver whose model type is not statically knowable',
                $silent,
            );

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
                $silent,
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
     * Mirrors Laravel's resource resolution: `#[UseResource]` first, then `guessResourceName()`.
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
