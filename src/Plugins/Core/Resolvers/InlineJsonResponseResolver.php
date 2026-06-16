<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Override;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Return_;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Attributes\PrimaryResponseAuthoringAttribute;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Plugins\Core\Support\InlineJsonCallReader;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\OperationBuilder;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\ConditionalContextPolicy;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\NonLiteralValueException;
use Radiergummi\OpenApi\Support\MethodBody\StatementNodeFinder;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

use function array_filter;
use function array_values;
use function is_a;
use function is_int;
use function sprintf;

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
 * Call recognition and literal status/body reading live in the shared {@see InlineJsonCallReader};
 * this resolver applies the *primary-slot* policy on top of those facts: only a 2xx status may
 * claim the success response, so a straight-line non-2xx literal (the guarded-success +
 * terminal-error-fallback idiom) degrades with a note rather than evicting the operation's success
 * response — that refused 4xx/5xx literal is the error machinery's job
 * ({@see \Radiergummi\OpenApi\Plugins\Core\ErrorContributors\InlineJsonErrorContributor}, #238). A
 * 204 documents without content — the runtime strips the body. A `response()->noContent(<status>)`
 * is matched as a body-less response at its status argument (204 when absent, the literal 2xx
 * otherwise); a non-literal or non-2xx status degrades.
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

    private StatementNodeFinder $statementNodeFinder;

    public function __construct(
        private MethodBodyScanner $scanner,
        private InlineJsonCallReader $callReader,
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

        $noContentCall = $this->findNoContentCall($statements);

        if ($noContentCall instanceof MethodCall) {
            $status = $this->statusFromNoContent($noContentCall, $method);

            if ($status === false) {
                return null;
            }

            // Writing noContent() at all is an affirmative choice of a body-less success response,
            // so it beats the resource-action convention regardless of whether a status argument
            // is present (absent → 204; literal 2xx → that status). Only the value varies.
            return $this->markStatusExplicit(
                new OA\Response([
                    'response' => (string) $status,
                    'description' => HttpFoundationResponse::$statusTexts[$status] ?? sprintf('HTTP %d', $status),
                ]),
                true,
            );
        }

        $call = $this->findJsonCall($statements);

        if (!$call instanceof MethodCall) {
            $conditionalCall = $this->statementNodeFinder->findFirst(
                $statements,
                ConditionalContextPolicy::IncludeConditionalContexts,
                fn(Node $node): bool => $this->callReader->isJsonHelperCall($node),
            );

            if ($conditionalCall !== null) {
                $this->note($method, 'only runs conditionally, so it is not the canonical success response');
            }

            return null;
        }

        return $this->responseFromCall($call, $method, $statements);
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
     * The matched `response()->noContent()` call, preferring a *returned* call over one only
     * assigned to a variable, mirroring {@see self::findJsonCall}. `noContent()` always documents
     * a body-less response; {@see self::statusFromNoContent()} reads its status argument.
     *
     * @param list<Stmt> $statements
     */
    private function findNoContentCall(array $statements): ?MethodCall
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
                fn(Node $node): bool => $this->callReader->isFactoryMethodCall($node, 'nocontent'),
            );

            if ($call instanceof MethodCall) {
                return $call;
            }
        }

        return null;
    }

    /**
     * The status of a matched `response()->noContent(<status>)`: 204 when the argument is absent
     * (the helper default), the literal/class-constant 2xx status when present, or false (degrade
     * with a note) when the argument is non-literal or a non-2xx literal — the same contract
     * {@see self::ensureSuccessStatus()} follows. The response stays body-less either way.
     */
    private function statusFromNoContent(MethodCall $call, ReflectionMethod $method): int|false
    {
        $statusArgument = $this->noContentStatusArgument($call);

        if ($statusArgument === false) {
            $this->note(
                $method,
                'has no statically readable status code, so the body must not be documented under a guessed status',
                'response()->noContent()',
            );

            return false;
        }

        if ($statusArgument === null) {
            return 204;
        }

        return $this->ensureSuccessStatus($statusArgument, $method, 'response()->noContent()') ?? false;
    }

    /**
     * The literal status argument of a `noContent()` call: null when absent (the 204 default),
     * an int when a statically readable literal/class-constant, or false when present but not
     * statically readable.
     */
    private function noContentStatusArgument(MethodCall $call): int|false|null
    {
        $argument = array_find(
            $call->getArgs(),
            static fn($arg, $index) => $arg->name === null ? $index === 0 : $arg->name->toString() === 'status',
        );

        if ($argument === null) {
            return null;
        }

        if (!$argument->unpack) {
            try {
                $status = AstLiteralEvaluator::evaluate($argument->value);
            } catch (NonLiteralValueException) {
                $status = null;
            }

            if (is_int($status)) {
                return $status;
            }
        }

        return false;
    }

    // endregion

    // region Response construction

    private function note(
        ReflectionMethod $method,
        string $reason,
        string $callExpression = 'response()->json()',
    ): void {
        $this->logger->notice(
            sprintf(
                '%s call in %s::%s %s; no response inferred. '
                . 'Annotate the action with #[Response] to document it.',
                $callExpression,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
                $reason,
            ),
        );
    }

    /**
     * The status itself when it is a 2xx, or null (refusal, with a note) otherwise. Only a success
     * status may claim the primary response: a straight-line non-2xx literal (`json([...], 403)`,
     * or a `->setStatusCode(403)`) is an error response, and taking it as primary would evict the
     * operation's success response.
     */
    private function ensureSuccessStatus(
        int $status,
        ReflectionMethod $method,
        string $callExpression = 'response()->json()',
    ): ?int {
        if ($status < 200 || $status > 299) {
            $this->note(
                $method,
                sprintf(
                    'has a literal non-2xx status (%d) — an error response must not claim the primary response',
                    $status,
                ),
                $callExpression,
            );

            return null;
        }

        return $status;
    }

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
                fn(Node $node): bool => $this->callReader->isJsonHelperCall($node),
            );

            if ($call instanceof MethodCall) {
                return $call;
            }
        }

        return null;
    }

    // endregion

    // region Guards & logging

    /**
     * Builds the primary response from a matched `json()` call by reading its facts via the shared
     * {@see InlineJsonCallReader} and applying the 2xx primary-slot policy. A degraded read, a
     * non-2xx status, or a non-readable body all refuse with the reader's note (the refused 4xx/5xx
     * literal becomes the error machinery's job in {@see InlineJsonErrorContributor}).
     *
     * @param list<Stmt> $statements
     */
    private function responseFromCall(MethodCall $call, ReflectionMethod $method, array $statements): ?OA\Response
    {
        $result = $this->callReader->read($statements, $call);

        if ($result->status === null) {
            $this->note($method, $result->statusDegradeReason ?? 'has no statically readable status code');

            return null;
        }

        // A status the author wrote in the call (or a ->setStatusCode chain) is ground truth, not a
        // default; the resource convention must defer to it rather than relabel the body (#240). An
        // absent status argument is the helper's own 200 default, which the convention may override.
        $statusIsExplicit = $this->callReader->isJsonHelperCall($call)
            && $this->hasExplicitStatus($call, $statements);

        $status = $this->ensureSuccessStatus($result->status, $method);

        if ($status === null) {
            return null;
        }

        // A 204 must not carry a body — the runtime strips it (`Response::prepare()`), so the
        // literal body is not documented either.
        if ($status === 204) {
            return $this->markStatusExplicit(
                new OA\Response(['response' => '204', 'description' => 'No Content']),
                $statusIsExplicit,
            );
        }

        if (!$result->bodyReadable) {
            $this->note($method, $result->bodyDegradeReason ?? 'has no statically readable body');

            return null;
        }

        // An empty literal body carries no shape worth documenting — silent by the
        // found-but-unreadable convention (nothing is unreadable here).
        if ($result->bodySchema === null) {
            return null;
        }

        return $this->markStatusExplicit(new OA\Response([
            'response' => (string) $status,
            'description' => HttpFoundationResponse::$statusTexts[$status] ?? sprintf('HTTP %d', $status),
            'content' => [MediaType::Json->schema($result->bodySchema)],
        ]), $statusIsExplicit);
    }

    /**
     * Whether the response status is author-written (a `json()` status argument or a chained
     * `->setStatusCode()`) rather than the helper's 200 default — the resource convention must
     * defer to an explicit status.
     *
     * @param list<Stmt> $statements
     */
    private function hasExplicitStatus(MethodCall $call, array $statements): bool
    {
        $statusArgument = array_find(
            $call->getArgs(),
            static fn($arg, $index) => $arg->name === null ? $index === 1 : $arg->name->toString() === 'status',
        );

        if ($statusArgument !== null) {
            return true;
        }

        // A chained ->setStatusCode() also makes the status explicit.
        $setStatusCode = $this->statementNodeFinder->findFirst(
            $statements,
            ConditionalContextPolicy::IncludeConditionalContexts,
            static fn(Node $node): bool
                => $node instanceof MethodCall
                && $node->name instanceof Identifier
                && $node->name->toLowerString() === 'setstatuscode',
        );

        return $setStatusCode !== null;
    }

    // endregion
}
