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
 * Infers the success-response model schema from a directly-returned `Model::find()` /
 * `findOrFail()` / `firstOrFail()` static call in the controller body — a Tier-1 bounded scan
 * (epic #5, issue #97), feeding the same Tier-0 model→schema reader as
 * {@see EloquentModelResponseResolver}.
 *
 * The class must be statically resolvable: only a `Model::method()` static call whose class is a
 * literal name (NameResolver-resolved to an FQCN) and whose lowercased method is in the whitelist
 * is matched. `$class::find()` (dynamic class), a wrapped result (`new UserResource(User::find())`,
 * `response()->json(User::find())`), a `find()` assigned to a variable and returned indirectly, or a
 * non-whitelist call (`User::all()`, `->where()->first()`) are all refused — the latter cases with a
 * generation-log NOTICE so the author learns why nothing was inferred.
 *
 * Scans the first {@see self::STATEMENT_LIMIT} statements under
 * {@see ConditionalContextPolicy::SkipConditionalContexts}: a model return inside an `if`/ternary is
 * not unambiguously the canonical success body (same reasoning as the primary-slot json scan,
 * opposite of the error scans). The return-type guard defers any signature that already carries
 * schema information (a typed Model/Collection/Data return) to the Tier-0 resolvers registered
 * earlier, so this scan only fires on untyped/builtin/HTTP-response returns.
 *
 * `find()` may return null where `findOrFail()`/`firstOrFail()` throw; the success schema is the
 * same model `$ref` in all three cases, and nullability of the 200 body is not modeled (consistent
 * with {@see EloquentModelResponseResolver}, which accepts `?Model` without a nullable schema).
 *
 * @internal
 */
#[Scoped]
final readonly class FindReturnModelResponseResolver implements PrimaryResponseResolver
{
    private const int STATEMENT_LIMIT = 10;

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
    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        $method = $descriptor->method;

        if ($method === null || !$this->returnTypeAllowsBodyScan($method)) {
            return null;
        }

        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);

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
            // The class is dynamic (`$class::find()`) — lookup-shaped but not statically
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
     * Whether the declared return type leaves room for a body scan: untyped, a builtin, or an HTTP
     * response class. Any other named type — a Model, Collection, Data class, Resource, or
     * paginator — is Tier-0 territory {@see EloquentModelResponseResolver} (and the other signature
     * resolvers) own; union and intersection types are refused rather than arbitrated. Mirrors
     * {@see InlineJsonResponseResolver::returnTypeAllowsBodyScan}.
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
     * The first lookup call that is the *direct* expression of an unconditional `return` — i.e.
     * `return User::find($id);`, where the static call is the whole returned value, not nested
     * inside another expression. A wrapped lookup (`return new UserResource(User::find())`,
     * `return response()->json(User::find())`) or one assigned to a variable is therefore not
     * matched here; those degrade via {@see noteUnmatchedLookup}. Only top-level `Return_`
     * statements participate, so a `return` inside an `if` (nested in an `If_` node) is skipped.
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

    /**
     * Whether the node is a static call whose method (lowercased) is a lookup method. The class is
     * not constrained here — {@see resolveModelClass} decides whether it resolves to a Model.
     */
    private function isLookupStaticCall(Node $node): bool
    {
        return $node instanceof StaticCall
            && !$node->isFirstClassCallable()
            && $node->name instanceof Identifier
            && in_array($node->name->toLowerString(), self::LOOKUP_METHODS, true);
    }

    /**
     * Notes (once) when a lookup-shaped call exists in the body but was not a direct return — the
     * author learns why nothing was inferred. A body with no lookup call at all stays silent: there
     * is nothing unreadable to report.
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
     * The resolved Model subclass behind the static call's class reference, or null when the class
     * is dynamic (`$class::find()` — not a literal `Name`), unknown, or not a Model.
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

    /**
     * Wraps a schema in the `200 OK application/json` response — the same shape
     * {@see EloquentModelResponseResolver} emits (its `jsonResponse()` is private, so this
     * three-line shape is replicated rather than shared, keeping the scope surgical).
     */
    private function jsonResponse(OA\Schema $schema): OA\Response
    {
        return new OA\Response([
            'response' => '200',
            'description' => 'OK',
            'content' => [MediaType::Json->schema($schema)],
        ]);
    }
}
