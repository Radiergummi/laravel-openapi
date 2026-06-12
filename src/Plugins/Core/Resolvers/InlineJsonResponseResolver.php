<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Return_;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Attributes\PrimaryResponseAuthoringAttribute;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\OperationBuilder;
use Radiergummi\OpenApi\Support\Generator\SchemaFromArrayDefinition;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\ConditionalContextPolicy;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\NonLiteralValueException;
use Radiergummi\OpenApi\Support\MethodBody\SchemaDefinitionFromLiteral;
use Radiergummi\OpenApi\Support\MethodBody\StatementNodeFinder;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

use function array_filter;
use function array_values;
use function function_exists;
use function in_array;
use function is_a;
use function is_int;
use function sprintf;
use function strtolower;

/**
 * Infers the primary response from a literal `response()->json([...])` call in the controller
 * method body — a Tier-1 bounded scan (epic #5, issue #14).
 *
 * Scans the first {@see self::STATEMENT_LIMIT} top-level statements under
 * {@see ConditionalContextPolicy::SkipConditionalContexts}: a `response()->json()` that only runs
 * conditionally is not the canonical success response (same reasoning as the inline-validation
 * request scan, opposite of the abort scan). A *returned* `json()` beats one only assigned to a
 * variable; among returned calls, the first wins. Only the global `response()` helper with zero
 * arguments followed by `->json(...)` is matched; the `Response` facade and `new JsonResponse()`
 * are out of scope by design.
 *
 * The `data` and `status` arguments resolve by position or by name against the helper signature
 * `json($data = [], $status = 200, ...)`. A literal array body becomes an object/array schema:
 * nested literal arrays recurse, literal scalars type their property, and a dynamic *value* under
 * a literal key keeps the property with an unconstrained schema — dropping a response property
 * would be silently wrong for spec consumers. A dynamic *key* (or spread) degrades the whole
 * call. A literal (or class-constant) `status` becomes the response status; a non-literal status
 * degrades the whole call — the body must not be documented under a guessed status. Only a 2xx
 * status may claim the primary response: a straight-line non-2xx literal (the guarded-success +
 * terminal-error-fallback idiom) degrades with a note rather than evicting the operation's
 * success response. A 204 documents without content — the runtime strips the body. A chained
 * call that can change the response's status or body (`->setStatusCode(...)`, `->setData(...)`)
 * degrades the call until those chains are read (#236); header/cookie chains stay matched.
 *
 * Degradation contract: a matched call that cannot be read statically is skipped with a
 * generation-log note (`#[Response]` is the escape hatch); a method without any matching call is
 * skipped silently, as is a body without shape information (`json()` / `json([])`). Explicit
 * `#[Response(2xx)]` attributes win in `OperationBuilder`'s primary-override path — not
 * re-implemented here — and an action carrying a {@see PrimaryResponseAuthoringAttribute}
 * (`#[ResponseResource]`, `#[FractalResponse]`) is never scanned: the attribute's own resolver
 * may sit later in the chain, and explicit authoring always wins. The return-type guard keeps
 * the scan away from actions whose signature already carries schema information (a typed
 * Model/Data/Resource/paginator return), so the Tier-0 resolvers stay authoritative regardless
 * of chain order.
 */
#[Scoped]
final readonly class InlineJsonResponseResolver implements PrimaryResponseResolver
{
    public const int STATEMENT_LIMIT = 10;

    /**
     * Positions in Laravel's `ResponseFactory::json($data = [], $status = 200, …)` signature,
     * used to resolve arguments by position or by name.
     */
    private const int DATA_ARGUMENT_POSITION = 0;

    private const int STATUS_ARGUMENT_POSITION = 1;

    /**
     * Chained methods (lowercased) that cannot change the response's status or body — header and
     * cookie decoration only. Any other chained call (`setStatusCode`, `setData`, `setNotModified`,
     * …) may invalidate what the matched call promised, so it degrades the scan until #236 reads
     * those chains.
     */
    private const array RESPONSE_PRESERVING_CHAIN_METHODS = ['header', 'withheaders', 'cookie', 'withcookie'];

    private StatementNodeFinder $statementNodeFinder;

    public function __construct(
        private MethodBodyScanner $scanner,
        private LoggerInterface $logger,
    ) {
        $this->statementNodeFinder = new StatementNodeFinder();
    }

    public function resolvePrimaryResponse(ActionDescriptor $descriptor): ?OA\Response
    {
        $method = $descriptor->method;

        if ($method === null || !$this->returnTypeAllowsBodyScan($method)) {
            return null;
        }

        // An explicit authoring attribute always wins (epic #5). Its consuming resolver may sit
        // later in the chain (#[ResponseResource] → ApiResources, #[FractalResponse] → Fractal),
        // so the scan steps aside rather than claim a response the author already described.
        if ($descriptor->declaresAttributeImplementing(PrimaryResponseAuthoringAttribute::class)) {
            return null;
        }

        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);

        if ($statements === []) {
            return null;
        }

        $call = $this->findJsonCall($statements);

        if (!$call instanceof MethodCall) {
            $conditionalCall = $this->statementNodeFinder->findFirst(
                $statements,
                ConditionalContextPolicy::IncludeConditionalContexts,
                fn(Node $node): bool => $this->isJsonHelperCall($node),
            );

            if ($conditionalCall !== null) {
                $this->note($method, 'only runs conditionally, so it is not the canonical success response');
            }

            return null;
        }

        if (!$this->chainPreservesResponse($statements, $call, $method)) {
            return null;
        }

        return $this->responseFromCall($call, $method);
    }

    /**
     * Whether the declared return type leaves room for a body scan: untyped, a builtin, or an
     * HTTP response class (`JsonResponse` & friends). Any other named type — a Model, Data class,
     * Resource, or paginator — is Tier-0 territory the signature resolvers own; union and
     * intersection types are refused rather than arbitrated.
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

    // region Call-shape matching

    /**
     * The matched `response()->json(...)` call, preferring *returned* calls: a `return`ed json()
     * is the response the action actually emits, while one assigned to a variable may never be.
     * Among returned matches the first wins; without any, the first match anywhere in the
     * scanned statements is taken.
     *
     * @param list<Stmt> $statements
     */
    private function findJsonCall(array $statements): ?MethodCall
    {
        $returnStatements = array_values(
            array_filter(
                $statements,
                static fn(Stmt $statement): bool => $statement instanceof Return_,
            ),
        );

        foreach ([$returnStatements, $statements] as $candidates) {
            $call = $this->statementNodeFinder->findFirst(
                $candidates,
                ConditionalContextPolicy::SkipConditionalContexts,
                fn(Node $node): bool => $this->isJsonHelperCall($node),
            );

            if ($call instanceof MethodCall) {
                return $call;
            }
        }

        return null;
    }

    /**
     * Whether the node is a `->json(...)` method call on a zero-argument `response()` helper
     * call. `response('content')` returns a response, not the factory, so any argument
     * disqualifies the receiver.
     */
    private function isJsonHelperCall(Node $node): bool
    {
        return $node instanceof MethodCall
            && !$node->isFirstClassCallable()
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === 'json'
            && $this->isResponseHelperCall($node->var);
    }

    /**
     * Names arrive resolved by the scanner's NameResolver pass. A fully-qualified name matches
     * when it is the root-namespace helper itself (`\response`); an *unqualified* name in a
     * namespaced file stays unresolved (PHP's runtime fallback), so it matches as Laravel's
     * global helper — unless a same-namespace function of that name is actually defined, in
     * which case PHP would call the user's function and we must not document it.
     */
    private function isResponseHelperCall(Expr $receiver): bool
    {
        if (
            !$receiver instanceof FuncCall
            || $receiver->isFirstClassCallable()
            || !$receiver->name instanceof Name
            || $receiver->name->toLowerString() !== 'response'
            || $receiver->getArgs() !== []
        ) {
            return false;
        }

        if ($receiver->name->isFullyQualified()) {
            return true;
        }

        $namespacedName = $receiver->name->getAttribute('namespacedName');

        return !($namespacedName instanceof Name && function_exists($namespacedName->toString()));
    }

    private function note(ReflectionMethod $method, string $reason): void
    {
        $this->logger->notice(
            sprintf(
                'response()->json() call in %s::%s %s; no response inferred. '
                . 'Annotate the action with #[Response] to document it.',
                $method->getDeclaringClass()->getName(),
                $method->getName(),
                $reason,
            ),
        );
    }

    // endregion

    // region Response construction

    /**
     * Whether every method call chained onto the matched `json()` leaves the response's status
     * and body untouched. Walks the chain outwards (`json(...)->header(...)->setStatusCode(...)`)
     * and degrades with a note on the first non-whitelisted link.
     *
     * @param list<Stmt> $statements
     */
    private function chainPreservesResponse(array $statements, MethodCall $call, ReflectionMethod $method): bool
    {
        $current = $call;

        while (($parent = $this->chainParentOf($statements, $current)) !== null) {
            $name = $parent->name instanceof Identifier ? $parent->name->toString() : null;

            if ($name === null || !in_array(strtolower($name), self::RESPONSE_PRESERVING_CHAIN_METHODS, true)) {
                $this->note(
                    $method,
                    sprintf(
                        'is chained into ->%s(), which may change the response status or body',
                        $name ?? '{dynamic}',
                    ),
                );

                return false;
            }

            $current = $parent;
        }

        return true;
    }

    /**
     * The method call whose receiver is exactly the given call (the next link outwards in a
     * fluent chain), or null when the call is not chained into another method call.
     *
     * @param list<Stmt> $statements
     */
    private function chainParentOf(array $statements, MethodCall $call): ?MethodCall
    {
        $parent = $this->statementNodeFinder->findFirst(
            $statements,
            ConditionalContextPolicy::IncludeConditionalContexts,
            static fn(Node $node): bool => $node instanceof MethodCall && $node->var === $call,
        );

        return $parent instanceof MethodCall ? $parent : null;
    }

    private function responseFromCall(MethodCall $call, ReflectionMethod $method): ?OA\Response
    {
        $arguments = $call->getArgs();

        foreach ($arguments as $argument) {
            if ($argument->unpack) {
                $this->note($method, 'spreads its arguments, so they cannot be read statically');

                return null;
            }
        }

        $dataArgument = $this->argument($arguments, 'data', self::DATA_ARGUMENT_POSITION);

        // No data argument means an empty `[]` body — readable, but carrying no shape worth
        // documenting. Silent by the found-but-unreadable convention: nothing is unreadable here.
        if ($dataArgument === null) {
            return null;
        }

        $status = $this->resolveStatus($arguments, $method);

        if ($status === null) {
            return null;
        }

        // A status the author wrote in the call is ground truth, not a default; the resource
        // convention must defer to it rather than relabel the body (#240). An absent status
        // argument is the helper's own 200 default, which the convention may still override.
        $statusIsExplicit = $this->argument($arguments, 'status', self::STATUS_ARGUMENT_POSITION) !== null;

        // A 204 must not carry a body — the runtime strips it (`Response::prepare()`), so the
        // literal body is not documented either.
        if ($status === 204) {
            return $this->markStatusExplicit(
                new OA\Response(['response' => '204', 'description' => 'No Content']),
                $statusIsExplicit,
            );
        }

        $definition = $this->bodyDefinition($dataArgument->value, $method);

        if ($definition === null) {
            return null;
        }

        return $this->markStatusExplicit(new OA\Response([
            'response' => (string) $status,
            'description' => HttpFoundationResponse::$statusTexts[$status] ?? sprintf('HTTP %d', $status),
            'content' => [MediaType::Json->schema(SchemaFromArrayDefinition::build($definition))],
        ]), $statusIsExplicit);
    }

    /**
     * Resolves an argument by position (unnamed) or by name, mirroring how PHP binds the call.
     *
     * @param array<int, Arg> $arguments
     */
    private function argument(array $arguments, string $name, int $position): ?Arg
    {
        foreach ($arguments as $index => $argument) {
            if ($argument->name === null ? $index === $position : $argument->name->toString() === $name) {
                return $argument;
            }
        }

        return null;
    }

    // endregion

    // region Schema definitions

    /**
     * The literal status argument, 200 when absent, or null (refusal) when present but not
     * statically readable — documenting the body under a guessed status would be wrong — or not
     * a 2xx. Only a success status may claim the primary response: a straight-line non-2xx
     * literal (`return response()->json(['message' => 'Unauthorized'], 403)` as the terminal
     * fallback after a guarded success) is an error response, and taking it as primary would
     * evict the operation's success response.
     *
     * @param array<int, Arg> $arguments
     */
    private function resolveStatus(array $arguments, ReflectionMethod $method): ?int
    {
        $statusArgument = $this->argument($arguments, 'status', self::STATUS_ARGUMENT_POSITION);

        if ($statusArgument === null) {
            return 200;
        }

        try {
            $status = AstLiteralEvaluator::evaluate($statusArgument->value);
        } catch (NonLiteralValueException) {
            $status = null;
        }

        if (!is_int($status)) {
            $this->note(
                $method,
                'has no statically readable status code, so the body must not be documented under a guessed status',
            );

            return null;
        }

        if ($status < 200 || $status > 299) {
            $this->note(
                $method,
                sprintf(
                    'has a literal non-2xx status (%d) — an error response must not claim the primary response',
                    $status,
                ),
            );

            return null;
        }

        return $status;
    }

    // endregion

    // region Guards & logging

    /**
     * Tags a response with the transient marker {@see OperationBuilder} reads to let an
     * author-written status win over the resource convention. The marker never reaches the
     * serialized document — OperationBuilder strips it.
     */
    private function markStatusExplicit(OA\Response $response, bool $explicit): OA\Response
    {
        if ($explicit) {
            $response->x = [OperationBuilder::EXPLICIT_STATUS_EXTENSION => true];
        }

        return $response;
    }

    /**
     * The plain schema-definition array for the `data` argument, or null when nothing is
     * documentable: an empty literal (silent — no shape information) or a non-literal expression
     * (noted — `$data` variables, model expressions, and `compact()` are Tier-2 dataflow and
     * belong to the `#[Response]` attribute). The literal-walking rules live in the shared
     * {@see SchemaDefinitionFromLiteral}.
     *
     * @return null|array<string, mixed>
     */
    private function bodyDefinition(Expr $data, ReflectionMethod $method): ?array
    {
        if ($data instanceof Array_) {
            if ($data->items === []) {
                return null;
            }

            try {
                return SchemaDefinitionFromLiteral::fromArrayNode($data);
            } catch (NonLiteralValueException) {
                $this->note(
                    $method,
                    'has an array literal whose structure (a key or spread entry) is not statically readable',
                );

                return null;
            }
        }

        try {
            $literal = AstLiteralEvaluator::evaluate($data);
        } catch (NonLiteralValueException) {
            $this->note($method, 'has no statically readable body');

            return null;
        }

        // A literal scalar (`json('ok')`, `json(true)`) is a valid JSON document; `null` and an
        // empty array carry no shape.
        $definition = SchemaDefinitionFromLiteral::fromLiteralValue($literal);

        return $definition === [] ? null : $definition;
    }

    // endregion
}
