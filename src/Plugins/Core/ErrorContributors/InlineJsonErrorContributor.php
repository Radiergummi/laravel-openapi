<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\ErrorContributors;

use Illuminate\Container\Attributes\Scoped;
use Override;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Plugins\Core\Support\InlineJsonCallReader;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\MethodBody\ConditionalContextPolicy;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\StatementNodeFinder;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function array_key_exists;
use function sprintf;

/**
 * Infers error responses from non-2xx `response()->json([...], <4xx/5xx>)` literals in the
 * controller method body — a Tier-1 bounded scan (epic #5, issue #238).
 *
 * {@see \Radiergummi\OpenApi\Plugins\Core\Resolvers\InlineJsonResponseResolver} reads these calls
 * for the primary response slot but *refuses* a non-2xx status (an error must not claim the
 * success response). The refused call still carries a known 4xx/5xx status and a known literal
 * body — exactly an error response, like the `abort(403, '…')` calls
 * {@see AbortErrorContributor} already routes into the error machinery. This contributor captures
 * that refused information, sharing the call recognition and literal reading with the resolver via
 * {@see InlineJsonCallReader}.
 *
 * Scans the first {@see self::STATEMENT_LIMIT} top-level statements under
 * {@see ConditionalContextPolicy::IncludeConditionalContexts} — an error `json()` in an `if`/guard
 * branch is exactly the outcome worth documenting (same reasoning as {@see AbortErrorContributor},
 * opposite the primary-slot scan). A literal body becomes the response's inlined schema (it wins
 * over the configured error envelope); a non-literal body degrades to a status-only descriptor
 * (the envelope fills the body). One descriptor per distinct status; on two branches at the same
 * status the first wins.
 *
 * Degradation contract: a non-literal *status* (`json([...], $code)`) is skipped with a
 * generation-log note (a body must not be documented under a guessed status); a 3xx literal is a
 * redirect, an intentional non-error the generator silently does not model. `#[Response]` for the
 * same status wins in the stage's drop-explicit pass — not re-implemented here.
 */
#[Scoped]
final readonly class InlineJsonErrorContributor implements ErrorResponseContributor
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

    /**
     * @return list<ErrorDescriptor>
     */
    #[Override]
    public function contribute(ActionDescriptor $descriptor): array
    {
        $method = $descriptor->method;

        if ($method === null) {
            return [];
        }

        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);

        if ($statements === []) {
            return [];
        }

        $calls = $this->statementNodeFinder->findAll(
            $statements,
            ConditionalContextPolicy::IncludeConditionalContexts,
            fn(Node $node): bool => $this->callReader->isJsonHelperCall($node),
        );

        /** @var array<int, ErrorDescriptor> $byStatus */
        $byStatus = [];

        foreach ($calls as $call) {
            if (!$call instanceof MethodCall) {
                continue;
            }

            $errorDescriptor = $this->descriptorFromCall($call, $descriptor, $method, $statements);

            // First branch wins for a given status — the operation gets one response per status.
            if ($errorDescriptor !== null && !array_key_exists($errorDescriptor->status, $byStatus)) {
                $byStatus[$errorDescriptor->status] = $errorDescriptor;
            }
        }

        return array_values($byStatus);
    }

    /**
     * @param list<Node\Stmt> $statements
     */
    private function descriptorFromCall(
        MethodCall $call,
        ActionDescriptor $action,
        ReflectionMethod $method,
        array $statements,
    ): ?ErrorDescriptor {
        $result = $this->callReader->read($statements, $call);

        if ($result->status === null) {
            // Matched, but the status is not statically readable — note it (the body must not be
            // documented under a guessed status).
            $this->note($method, $result->statusDegradeReason ?? 'has no statically readable status code');

            return null;
        }

        // Only 4xx/5xx are errors. A 2xx is the primary scan's job; a 3xx is a redirect — an
        // intentional non-error the generator silently does not model.
        if ($result->status < 400 || $result->status > 599) {
            return null;
        }

        return new ErrorDescriptor(
            status: $result->status,
            exceptionClass: $result->status === 404 ? NotFoundHttpException::class : HttpException::class,
            description: HttpFoundationResponse::$statusTexts[$result->status] ?? sprintf('HTTP %d', $result->status),
            action: $action,
            // A literal body is route-specific and must not be hoisted into the shared per-status
            // component; a status-only descriptor (non-literal body) shares fine.
            shareableDescription: $result->bodySchema === null,
            bodySchema: $result->bodySchema,
        );
    }

    private function note(ReflectionMethod $method, string $reason): void
    {
        $this->logger->notice(
            sprintf(
                'response()->json() call in %s::%s %s; no error response inferred. '
                . 'Annotate the action with #[Response] to document it.',
                $method->getDeclaringClass()->getName(),
                $method->getName(),
                $reason,
            ),
        );
    }
}
