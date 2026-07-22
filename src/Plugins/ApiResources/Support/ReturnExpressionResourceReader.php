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
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_ as NewExpression;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Return_;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Enums\PaginatorKind;
use Radiergummi\OpenApi\Routing\ResourceTarget;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\ReturnExpressionResolver;
use Radiergummi\OpenApi\Support\MethodBody\ReturnVariableRefusal;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\PhpDoc\ParsedDocBlock;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Reflector;

use function array_key_exists;
use function array_values;
use function class_exists;
use function class_parents;
use function count;
use function in_array;
use function interface_exists;
use function is_a;
use function is_string;
use function ltrim;
use function method_exists;
use function property_exists;
use function sprintf;

/**
 * Resolves the concrete API Resource an action returns when its signature names only a base type.
 * Consulted by {@see ResourceClassLocator} when the return type alone is insufficient.
 *
 * The `@return` generic wins over the body scan. The scan then matches a narrow whitelist in the
 * first {@see self::STATEMENT_LIMIT} statements: one unconditional return (or the variable it
 * names, assigned exactly once on the unconditional path), or several top-level returns that all
 * resolve to the same resource (bare `return;` / `return null;` ignored), unwrapped through
 * resource-preserving chains (`->additional(...)`). Recognised shapes: `X::collection(...)`, `X::collect(...)`,
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
     * Eloquent static methods that return a model instance (not a query builder), so a
     * `Model::x(...)->toResource()` receiver names the model. Query entrypoints (`query`, `where`,
     * …) are absent: they return a builder, whose element type is not statically knowable here.
     */
    private const array MODEL_RETURNING_STATICS = [
        'create',
        'forcecreate',
        'make',
        'find',
        'findornew',
        'findorfail',
        'first',
        'firstornew',
        'firstorcreate',
        'firstorfail',
        'updateorcreate',
        'sole',
    ];

    /**
     * Model instance methods that return the same model (instance or a refreshed copy of the same
     * class), so `$model->x(...)` preserves the model type. Used to see through a passthrough
     * callee's `return $param->refresh();`-style body.
     */
    private const array IDENTITY_PRESERVING_MODEL_CALLS = [
        'refresh',
        'fresh',
        'load',
        'loadmissing',
        'loadcount',
        'loadaggregate',
        'setrelation',
        'unsetrelation',
        'withoutrelations',
    ];

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

    /**
     * The method statements of the resolution in flight, so a receiver that is a local variable can
     * be traced to its assignment. Set per `resolve()` call; only ever read synchronously within it.
     *
     * @var list<Stmt>
     */
    private array $resolutionStatements = [];

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
        $this->resolutionStatements = $statements;

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
        $this->resolutionStatements = $statements;
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
     * The Model class a `->toResource()` receiver expression resolves to, or null.
     *
     * Resolves the shapes whose type is statically declared, no dataflow:
     *
     * - a Model-typed parameter: `$model->toResource()`;
     * - a property whose declared type is a Model: `$document->author->toResource()`;
     * - a method call whose declared return type is a concrete Model:
     *   `$repository->find($id)->toResource()`;
     * - a "passthrough" call whose declared return type is the base `Model` (or `self`/`static`)
     *   and which takes a Model-typed argument that does resolve: `$request->resolve($model)`,
     *   `$this->reload($model)`. The returned instance is the one passed in, so its argument
     *   carries the concrete type.
     *
     * @return null|class-string<Model>
     *
     * @throws ReflectionException
     */
    private function receiverModelClass(Expr $receiver, ReflectionMethod $method): ?string
    {
        if ($receiver instanceof Variable && is_string($receiver->name)) {
            $parameterModel = $this->parameterModelClass($method, $receiver->name);

            if ($parameterModel !== null) {
                return $parameterModel;
            }

            // An `assert($var instanceof Model)` narrowing types the local directly, even when its
            // assignment expression is not itself statically resolvable.
            $asserted = $this->assertedModelClass($receiver->name);

            if ($asserted !== null) {
                return $asserted;
            }

            // Otherwise trace the local's single unconditional assignment and resolve that.
            $assigned = $this->expressionAssignedTo($receiver, $this->resolutionStatements, $method, log: false);

            return $assigned === null ? null : $this->receiverModelClass($assigned, $method);
        }

        if ($receiver instanceof NewExpression && $receiver->class instanceof Name) {
            $class = $receiver->class->toString();

            return is_a($class, Model::class, allow_string: true) ? $class : null;
        }

        if (
            $receiver instanceof StaticCall
            && $receiver->class instanceof Name
            && $receiver->name instanceof Identifier
        ) {
            $class = $receiver->class->toString();

            return in_array($receiver->name->toLowerString(), self::MODEL_RETURNING_STATICS, strict: true)
                && is_a($class, Model::class, allow_string: true)
                    ? $class
                    : null;
        }

        if ($receiver instanceof PropertyFetch && $receiver->name instanceof Identifier) {
            $ownerClass = $this->expressionClass($receiver->var, $method);

            return $ownerClass === null
                ? null
                : $this->propertyModelClass($ownerClass, $receiver->name->toString());
        }

        if ($receiver instanceof MethodCall && $receiver->name instanceof Identifier) {
            return $this->methodCallModelClass($receiver, $method);
        }

        return null;
    }

    /**
     * The Model class asserted for a local via `assert($var instanceof Model)`, or null.
     *
     * A common narrowing after a loosely-typed lookup (`$x = $request->user()->customer;
     * assert($x instanceof Customer);`); the assertion states the concrete type the assignment
     * expression alone does not carry.
     *
     * @return null|class-string<Model>
     */
    private function assertedModelClass(string $variableName): ?string
    {
        foreach ($this->resolutionStatements as $statement) {
            if (!$statement instanceof Stmt\Expression || !$statement->expr instanceof FuncCall) {
                continue;
            }

            $call = $statement->expr;

            if (!$call->name instanceof Name || $call->name->toLowerString() !== 'assert') {
                continue;
            }

            $argument = $call->getArgs()[0]->value ?? null;

            if (
                !$argument instanceof Instanceof_
                || !($argument->expr instanceof Variable && $argument->expr->name === $variableName)
                || !$argument->class instanceof Name
            ) {
                continue;
            }

            $class = $argument->class->toString();

            if (is_a($class, Model::class, allow_string: true)) {
                /** @var class-string<Model> $class */
                return $class;
            }
        }

        return null;
    }

    /**
     * The Model resolved from a method-call receiver: its declared concrete return type, or, for a
     * verified base-`Model` passthrough, the model type of the argument the callee returns.
     *
     * @return null|class-string<Model>
     *
     * @throws ReflectionException
     */
    private function methodCallModelClass(MethodCall $call, ReflectionMethod $method): ?string
    {
        $ownerClass = $this->expressionClass($call->var, $method);

        if ($ownerClass === null || !($call->name instanceof Identifier)) {
            return null;
        }

        $returnType = $this->methodReturnType($ownerClass, $call->name->toString());

        if ($returnType === null) {
            return null;
        }

        // A concrete Model return names the resource directly.
        if ($returnType !== Model::class && is_a($returnType, Model::class, allow_string: true)) {
            /** @var class-string<Model> $returnType */
            return $returnType;
        }

        // A base-Model (or self/static) return may be a passthrough: the concrete type rides in on
        // the argument. Anything else (a non-Model return) is not a resource source.
        if ($returnType !== Model::class && !$this->isSelfReturn($ownerClass, $returnType)) {
            return null;
        }

        // Only trust the passthrough when the callee's declared type or body ties the return to one
        // of its parameters. An identity `@return T` generic states this outright (regardless of
        // body branches); otherwise the body must actually return the parameter (e.g.
        // `resolve(Model $x): Model { return $x; }`). A method that merely has a base-Model
        // signature but returns something unrelated must not borrow an argument's type, or the
        // response schema would be silently wrong.
        $parameterIndex = $this->genericReturnParameterIndex($ownerClass, $call->name->toString())
            ?? $this->calleeReturnedParameterIndex($ownerClass, $call->name->toString());

        if ($parameterIndex === null) {
            return null;
        }

        $argument = $call->getArgs()[$parameterIndex]->value ?? null;

        return $argument === null ? null : $this->receiverModelClass($argument, $method);
    }

    /**
     * The zero-based index of the parameter a method returns by identity, or null when it does not
     * return a parameter (or the body is not a single statically-readable return).
     *
     * Looks through identity-preserving model calls on the parameter (`refresh()`, `fresh()`,
     * `load()`, …), which return the same model instance/shape.
     *
     * @param class-string $ownerClass
     *
     * @throws ReflectionException
     */
    private function calleeReturnedParameterIndex(string $ownerClass, string $methodName): ?int
    {
        if (!method_exists($ownerClass, $methodName)) {
            return null;
        }

        $callee = new ReflectionMethod($ownerClass, $methodName);

        if ($callee->isAbstract() || $callee->getDeclaringClass()->isInterface()) {
            return null;
        }

        $statements = $this->scanner->firstStatements($callee, self::STATEMENT_LIMIT);
        $returns = $this->methodLevelReturns($statements);

        if (count($returns) !== 1 || $returns[0]->expr === null) {
            return null;
        }

        $returnedName = $this->identityParameterName($returns[0]->expr);

        if ($returnedName === null) {
            return null;
        }

        foreach ($callee->getParameters() as $index => $parameter) {
            if ($parameter->getName() === $returnedName) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The zero-based index of the parameter an identity `@return T` generic borrows its type from,
     * or null when the callee declares no such generic.
     *
     * Matches the declared-type idiom `@template T … @param T $x … @return T`: the method's generic
     * return type *is* the argument's type, so the concrete resource rides in on that argument.
     * Unlike {@see calleeReturnedParameterIndex()} this reads only the docblock, so a multi-branch
     * body does not defeat it. Requires the `@return` to be a bare template identifier bound to
     * exactly one parameter; anything richer (`@return T[]`, `@return Collection<T>`, or two
     * parameters typed `T`) is an ambiguous borrow and refuses.
     *
     * @param class-string $ownerClass
     *
     * @throws ReflectionException
     */
    private function genericReturnParameterIndex(string $ownerClass, string $methodName): ?int
    {
        if (!method_exists($ownerClass, $methodName)) {
            return null;
        }

        $method = new ReflectionMethod($ownerClass, $methodName);
        $docComment = $method->getDocComment();

        if ($docComment === false) {
            return null;
        }

        $parsed = $this->docBlockParser->parse($docComment);
        $returnType = $parsed->returnType();

        if (!$returnType instanceof IdentifierTypeNode) {
            return null;
        }

        if (!in_array($returnType->name, $this->templateNames($parsed), strict: true)) {
            return null;
        }

        $parameterName = $this->soleParameterNameTypedAs($parsed, $returnType->name);

        if ($parameterName === null) {
            return null;
        }

        foreach ($method->getParameters() as $index => $parameter) {
            if ($parameter->getName() === $parameterName) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The names declared by the method's `@template` tags.
     *
     * @return list<string>
     */
    private function templateNames(ParsedDocBlock $parsed): array
    {
        $names = [];

        foreach ($parsed->tagValues('@template') as $tag) {
            if ($tag instanceof TemplateTagValueNode) {
                $names[] = $tag->name;
            }
        }

        return $names;
    }

    /**
     * The bare parameter name whose `@param` type is exactly the given template, or null when none
     * is — or more than one is, an ambiguous borrow.
     */
    private function soleParameterNameTypedAs(ParsedDocBlock $parsed, string $templateName): ?string
    {
        $names = [];

        foreach ($parsed->tagValues('@param') as $tag) {
            if (
                $tag instanceof ParamTagValueNode
                && $tag->type instanceof IdentifierTypeNode
                && $tag->type->name === $templateName
            ) {
                $names[] = ltrim($tag->parameterName, '$');
            }
        }

        return count($names) === 1 ? $names[0] : null;
    }

    /**
     * The variable name an expression is the identity of: a bare `$var`, or `$var` behind a chain
     * of identity-preserving model calls (`$var->refresh()`, `$var->fresh()->load(...)`). Null for
     * anything else.
     */
    private function identityParameterName(Expr $expression): ?string
    {
        while (
            $expression instanceof MethodCall
            && $expression->name instanceof Identifier
            && in_array($expression->name->toLowerString(), self::IDENTITY_PRESERVING_MODEL_CALLS, strict: true)
        ) {
            $expression = $expression->var;
        }

        return $expression instanceof Variable && is_string($expression->name) ? $expression->name : null;
    }

    /**
     * The class an expression evaluates to, for receiver-chain resolution: `$this`, a typed
     * parameter, a typed property, or a method's declared return type. Null when not statically
     * known.
     *
     * @return null|class-string
     *
     * @throws ReflectionException
     */
    private function expressionClass(Expr $expression, ReflectionMethod $method): ?string
    {
        if ($expression instanceof Variable) {
            if ($expression->name === 'this') {
                return $method->getDeclaringClass()->getName();
            }

            return is_string($expression->name)
                ? $this->parameterClass($method, $expression->name)
                : null;
        }

        if ($expression instanceof PropertyFetch && $expression->name instanceof Identifier) {
            $ownerClass = $this->expressionClass($expression->var, $method);

            return $ownerClass === null
                ? null
                : $this->propertyClass($ownerClass, $expression->name->toString());
        }

        if ($expression instanceof MethodCall && $expression->name instanceof Identifier) {
            $ownerClass = $this->expressionClass($expression->var, $method);
            $returnType = $ownerClass === null
                ? null
                : $this->methodReturnType($ownerClass, $expression->name->toString());

            return $returnType !== null && (class_exists($returnType) || interface_exists($returnType))
                ? $returnType
                : null;
        }

        return null;
    }

    /**
     * The class the named parameter is typed with (any class, not only Models), or null.
     *
     * @return null|class-string
     */
    private function parameterClass(ReflectionMethod $method, string $parameterName): ?string
    {
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getName() !== $parameterName) {
                continue;
            }

            $type = $parameter->getType();

            return $type instanceof ReflectionNamedType && !$type->isBuiltin()
                ? $this->asClassString($type->getName())
                : null;
        }

        return null;
    }

    /**
     * The declared class of a property on the owner class, or null.
     *
     * @param class-string $ownerClass
     *
     * @return null|class-string
     *
     * @throws ReflectionException
     */
    private function propertyClass(string $ownerClass, string $property): ?string
    {
        if (property_exists($ownerClass, $property)) {
            $type = new ReflectionProperty($ownerClass, $property)->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                return $this->asClassString($type->getName());
            }
        }

        // Eloquent relations and accessors have no native typed property; their type is declared in
        // a class-level `@property`/`@property-read` tag (conventionally on the model, or a parent).
        return $this->propertyClassFromDocBlock($ownerClass, $property);
    }

    /**
     * The class named by a `@property`/`@property-read`/`@property-write` tag for the property,
     * walking the class and its parents, or null. Nullable tags (`@property ?Foo`, `Foo|null`)
     * resolve to the non-null class.
     *
     * @param class-string $ownerClass
     *
     * @return null|class-string
     *
     * @throws ReflectionException
     */
    private function propertyClassFromDocBlock(string $ownerClass, string $property): ?string
    {
        $tag = $this->propertyTagType($ownerClass, $property);

        if ($tag === null) {
            return null;
        }

        [$type, $context] = $tag;
        $resolved = $this->typeNodeResolver->resolveClassName($type, $context);

        return $resolved !== null && (class_exists($resolved) || interface_exists($resolved)) ? $resolved : null;
    }

    /**
     * The `@property`/`@property-read`/`@property-write` type node declared for the property,
     * walking the class and its parents, paired with the reflection whose namespace context
     * resolves it. Null when no such tag exists.
     *
     * @param class-string $ownerClass
     *
     * @return null|array{0: TypeNode, 1: ReflectionClass<object>}
     */
    private function propertyTagType(string $ownerClass, string $property): ?array
    {
        foreach ([$ownerClass, ...array_values(class_parents($ownerClass) ?: [])] as $class) {
            $reflection = new ReflectionClass($class);
            $docComment = $reflection->getDocComment();

            if ($docComment === false) {
                continue;
            }

            $parsed = $this->docBlockParser->parse($docComment);

            foreach (['@property', '@property-read', '@property-write'] as $tagName) {
                foreach ($parsed->tagValues($tagName) as $tag) {
                    if ($tag instanceof PropertyTagValueNode && ltrim($tag->propertyName, '$') === $property) {
                        return [$tag->type, $reflection];
                    }
                }
            }
        }

        return null;
    }

    /**
     * The element Model of a `->toResourceCollection()` receiver whose declared type is a
     * `Collection<…, Model>`-style generic, or null. Reads the generic value type from a
     * `@property` collection relation (`$model->relation`) or a method's `@return C<Model>`
     * (`$owner->items()`); no dataflow, so an untyped local (e.g. a query-builder `->get()`) refuses.
     *
     * @return null|class-string<Model>
     *
     * @throws ReflectionException
     */
    private function receiverCollectionElementModel(Expr $receiver, ReflectionMethod $method): ?string
    {
        if ($receiver instanceof PropertyFetch && $receiver->name instanceof Identifier) {
            $ownerClass = $this->expressionClass($receiver->var, $method);
            $tag = $ownerClass === null ? null : $this->propertyTagType($ownerClass, $receiver->name->toString());

            return $tag === null ? null : $this->modelFromGeneric($tag[0], $tag[1]);
        }

        if ($receiver instanceof MethodCall && $receiver->name instanceof Identifier) {
            $ownerClass = $this->expressionClass($receiver->var, $method);
            $methodName = $receiver->name->toString();

            if ($ownerClass === null || !method_exists($ownerClass, $methodName)) {
                return null;
            }

            $callee = new ReflectionMethod($ownerClass, $methodName);
            $docComment = $callee->getDocComment();

            if ($docComment === false) {
                return null;
            }

            $returnType = $this->docBlockParser->parse($docComment)->returnType();

            return $returnType === null ? null : $this->modelFromGeneric($returnType, $callee);
        }

        return null;
    }

    /**
     * The Model class named by a generic type's value parameter (`Collection<int, Foo>` → `Foo`),
     * resolved in the given namespace context, or null when it is absent or not a Model.
     *
     * @return null|class-string<Model>
     */
    private function modelFromGeneric(TypeNode $node, Reflector $context): ?string
    {
        $element = $this->typeNodeResolver->genericValueClass($node, $context);

        if ($element !== null && is_a($element, Model::class, allow_string: true)) {
            /** @var class-string<Model> $element */
            return $element;
        }

        return null;
    }

    /**
     * Narrows a reflected type name to a known class-string, or null when no such class/interface
     * is loadable.
     *
     * @return null|class-string
     */
    private function asClassString(string $name): ?string
    {
        return class_exists($name) || interface_exists($name) ? $name : null;
    }

    /**
     * The declared property class when it is a Model subclass, or null.
     *
     * @param class-string $ownerClass
     *
     * @return null|class-string<Model>
     *
     * @throws ReflectionException
     */
    private function propertyModelClass(string $ownerClass, string $property): ?string
    {
        $class = $this->propertyClass($ownerClass, $property);

        if ($class !== null && is_a($class, Model::class, allow_string: true)) {
            /** @var class-string<Model> $class */
            return $class;
        }

        return null;
    }

    /**
     * The class named by a method's declared return type (nullable/`self`/`static` resolved), or
     * null when untyped or a builtin.
     *
     * @param class-string $ownerClass
     *
     * @return null|class-string
     *
     * @throws ReflectionException
     */
    private function methodReturnType(string $ownerClass, string $methodName): ?string
    {
        if (!method_exists($ownerClass, $methodName)) {
            return null;
        }

        $type = new ReflectionMethod($ownerClass, $methodName)->getReturnType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        // `self`/`static` name the receiver's own class.
        return in_array($name, ['self', 'static'], strict: true) ? $ownerClass : $this->asClassString($name);
    }

    /**
     * Whether a resolved return type is the receiver's own class (a `self`/`static`/`$this`-style
     * passthrough).
     *
     * @param class-string $ownerClass
     */
    private function isSelfReturn(string $ownerClass, string $returnType): bool
    {
        return $returnType === $ownerClass;
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
            $elementModel = $this->receiverCollectionElementModel($call->var, $method);
            $resourceClass = $elementModel === null ? null : $this->conventionalResourceFor($elementModel);

            if ($resourceClass !== null) {
                return new ResourceTarget(
                    $resourceClass,
                    isCollection: true,
                    paginated: $this->endsInPaginatingCall($call->var),
                );
            }

            $this->note(
                $method,
                'calls ->toResourceCollection() without naming the resource class',
                $silent,
            );

            return null;
        }

        $modelClass = $this->receiverModelClass($call->var, $method);

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
