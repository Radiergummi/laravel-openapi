<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Model;
use OpenApi\Annotations as OA;
use Override;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Return_;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponse;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\MethodBody\ConditionalContextPolicy;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\StatementNodeFinder;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;
use Throwable;

use function class_exists;
use function in_array;
use function is_a;
use function sprintf;

/**
 * Infers the success-response schema from a directly-returned `Model::find()` /
 * `findOrFail()` / `firstOrFail()` static call. Only a literal-FQCN, unconditional,
 * direct-return call is matched; dynamic classes, wrappers, and conditional returns degrade
 * gracefully. All three methods produce the same model `$ref`; nullability is not modeled.
 *
 * @internal
 */
#[Scoped]
final readonly class FindReturnModelResponseResolver implements PrimaryResponseResolver
{
    /**
     * Pathological-input backstop, not a semantic bound: the guard that makes a match sound is the
     * directly-returned, unconditional, literal-FQCN lookup call, not how far the scan looked. Set
     * far above ordinary method length so a run of guard clauses never hides the trailing return.
     */
    private const int RETURN_SCAN_STATEMENT_LIMIT = 100;

    private const array LOOKUP_METHODS = ['find', 'findorfail', 'firstorfail'];

    private StatementNodeFinder $statementNodeFinder;

    public function __construct(
        private MethodBodyScanner $scanner,
        private EloquentModelToSchema $modelToSchema,
        private ComponentSchemaRegistry $registry,
        private LoggerInterface $logger,
    ) {
        $this->statementNodeFinder = new StatementNodeFinder();
    }

    #[Override]
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?PrimaryResponse
    {
        $method = $descriptor->method;

        if ($method === null || !$this->returnTypeAllowsBodyScan($method)) {
            return null;
        }

        $statements = $this->scanner->firstStatements($method, self::RETURN_SCAN_STATEMENT_LIMIT);

        if ($statements === []) {
            return null;
        }

        $call = $this->directlyReturnedLookupCall($statements);

        if (!$call instanceof StaticCall) {
            $this->noteUnmatchedLookup($statements, $method);

            return null;
        }

        $modelClass = $this->resolveModelClass($call);

        if ($modelClass === null) {
            // The class is dynamic (`$class::find()`): lookup-shaped but not statically
            // resolvable. A non-Model literal class is handled separately (silent).
            if (!$call->class instanceof Name) {
                $this->noteUnmatchedLookup($statements, $method);
            }

            return null;
        }

        try {
            $reference = $this->registry->qualifyKey($this->modelToSchema->build($modelClass));
        } catch (Throwable $exception) {
            $this->logger->notice(
                sprintf(
                    'Failed to build the model schema for %s returned by %s::%s; no response inferred.',
                    $modelClass,
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                ),
                ['exception' => $exception],
            );

            return null;
        }

        return $this->jsonResponse(new OA\Schema(['ref' => $reference]));
    }

    /**
     * Whether the declared return type leaves room for a body scan: untyped, a builtin, or an
     * HTTP response class. Union/intersection types and named non-response types are refused.
     */
    private function returnTypeAllowsBodyScan(ReflectionMethod $method): bool
    {
        $returnType = $method->getReturnType();

        if ($returnType === null) {
            return true;
        }

        if (!$returnType instanceof ReflectionNamedType) {
            return false;
        }

        return $returnType->isBuiltin()
            || is_a($returnType->getName(), HttpFoundationResponse::class, true);
    }

    /**
     * The first directly-returned lookup call, e.g. `return User::find($id);`.
     * Wrapped, assigned, or conditional returns are skipped.
     *
     * @param list<Stmt> $statements
     */
    private function directlyReturnedLookupCall(array $statements): ?StaticCall
    {
        foreach ($statements as $statement) {
            if (!$statement instanceof Return_) {
                continue;
            }

            if ($statement->expr instanceof StaticCall && $this->isLookupStaticCall($statement->expr)) {
                return $statement->expr;
            }
        }

        return null;
    }

    /** Whether the node is a whitelisted lookup static call. Class resolution is left to {@see resolveModelClass}. */
    private function isLookupStaticCall(Node $node): bool
    {
        return $node instanceof StaticCall
            && !$node->isFirstClassCallable()
            && $node->name instanceof Identifier
            && in_array($node->name->toLowerString(), self::LOOKUP_METHODS, true);
    }

    /**
     * Logs a notice when a lookup call exists but was not a direct return. Silent when no lookup is found.
     *
     * @param list<Stmt> $statements
     */
    private function noteUnmatchedLookup(array $statements, ReflectionMethod $method): void
    {
        $anywhere = $this->statementNodeFinder->findFirst(
            $statements,
            ConditionalContextPolicy::IncludeConditionalContexts,
            fn(Node $node): bool => $this->isLookupStaticCall($node),
        );

        if ($anywhere === null) {
            return;
        }

        $this->logger->notice(
            sprintf(
                'A find()/findOrFail()/firstOrFail() call in %s::%s is not a directly-returned static '
                . 'model lookup (dynamic class, wrapped, assigned to a variable, or only conditional); '
                . 'no response inferred. Annotate the action with #[Response] to document it.',
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ),
        );
    }

    /**
     * The Model subclass named by the static call, or null when the class is dynamic, unknown, or not a Model.
     *
     * @return null|class-string<Model>
     */
    private function resolveModelClass(StaticCall $call): ?string
    {
        if (!$call->class instanceof Name) {
            return null;
        }

        $class = $call->class->toString();

        if (!class_exists($class) || !is_a($class, Model::class, true)) {
            return null;
        }

        /** @var class-string<Model> $class */
        return $class;
    }

    /** Wraps a schema in a `200 OK application/json` response. */
    private function jsonResponse(OA\Schema $schema): PrimaryResponse
    {
        return PrimaryResponse::of(new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [MediaType::Json->schema($schema)],
        ]));
    }
}
