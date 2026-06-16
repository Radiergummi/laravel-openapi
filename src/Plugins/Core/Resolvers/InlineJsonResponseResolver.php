<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Override;
use PhpParser\Node;
use PhpParser\Node\Arg;
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
 * Infers the primary response from a literal `response()->json([...])` or `response()->noContent()`
 * call in the controller method body. Only the global `response()` helper is matched; the facade
 * and `new JsonResponse()` are out of scope by design. Actions with a typed return or a
 * {@see PrimaryResponseAuthoringAttribute} are skipped.
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

        // An explicit authoring attribute always wins; step aside so its resolver can claim the slot.
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

            // noContent() is an affirmative body-less response; absent status defaults to 204.
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
     * Whether the declared return type allows a body scan: untyped, a builtin, or an HTTP response
     * class. Named types (Model, Data class, Resource, paginator) belong to the signature resolvers.
     * Union and intersection types are refused.
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
     * Returns the matched `response()->noContent()` call, preferring returned calls over assigned ones.
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
     * The effective status of a `response()->noContent(<status>)` call: 204 when absent, the literal
     * 2xx value when present, or false when non-literal or non-2xx.
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

        return $this->ensureSuccessStatus(
            $statusArgument,
            $method,
            'response()->noContent()',
        ) ?? false;
    }

    /**
     * The literal status argument of a `noContent()` call: null when absent, an int when statically
     * readable, or false when present but not statically readable.
     */
    private function noContentStatusArgument(MethodCall $call): int|false|null
    {
        $argument = array_find(
            $call->getArgs(),
            static fn(Arg $arg, int $index): bool => $arg->name === null ? $index === 0 : $arg->name->toString() === 'status',
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
     * Returns the status when it is 2xx, or null (with a notice) when non-2xx. A non-2xx literal
     * is an error response and must not claim the primary slot.
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
     * Tags a response with the transient marker that lets an explicit status win over the resource
     * convention. {@see OperationBuilder} strips the marker before serialization.
     */
    private function markStatusExplicit(OA\Response $response, bool $explicit): OA\Response
    {
        if ($explicit) {
            $response->x = [OperationBuilder::EXPLICIT_STATUS_EXTENSION => true];
        }

        return $response;
    }

    /**
     * Returns the matched `response()->json(...)` call, preferring returned calls over assigned ones.
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
     * @param list<Stmt> $statements
     */
    private function responseFromCall(MethodCall $call, ReflectionMethod $method, array $statements): ?OA\Response
    {
        $result = $this->callReader->read($statements, $call);

        if ($result->status === null) {
            $this->note($method, $result->statusDegradeReason ?? 'has no statically readable status code');

            return null;
        }

        // An explicit status defers the resource convention; an absent one (helper default 200) does not.
        $statusIsExplicit = $this->callReader->isJsonHelperCall($call)
            && $this->hasExplicitStatus($call, $statements);

        $status = $this->ensureSuccessStatus($result->status, $method);

        if ($status === null) {
            return null;
        }

        // 204 must not carry a body.
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

        // Empty literal body has no schema.
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
     * Whether the response status was explicitly set by the author, not just the helper's 200 default.
     *
     * @param list<Stmt> $statements
     */
    private function hasExplicitStatus(MethodCall $call, array $statements): bool
    {
        $statusArgument = array_find(
            $call->getArgs(),
            static fn(Arg $arg, int $index): bool => $arg->name === null ? $index === 1 : $arg->name->toString() === 'status',
        );

        if ($statusArgument !== null) {
            return true;
        }

        // A chained ->setStatusCode() is also explicit.
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
