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
 * controller method body.
 *
 * {@see \Radiergummi\OpenApi\Plugins\Core\Resolvers\InlineJsonResponseResolver} handles 2xx;
 * this contributor captures the refused 4xx/5xx calls and routes them into the error machinery
 * via {@see InlineJsonCallReader}. Scans the first {@see self::STATEMENT_LIMIT} statements
 * including conditional branches. A literal body becomes the inlined schema; non-literal degrades
 * to status-only. First branch wins per status. Non-literal statuses are logged; 3xx is ignored.
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
            // Status is not statically readable; a body must not be documented under a guessed status.
            $this->note($method, $result->statusDegradeReason ?? 'has no statically readable status code');

            return null;
        }

        // Only 4xx/5xx; 2xx is the primary scan's job, 3xx is a redirect.
        if ($result->status < 400 || $result->status > 599) {
            return null;
        }

        return new ErrorDescriptor(
            status: $result->status,
            exceptionClass: $result->status === 404 ? NotFoundHttpException::class : HttpException::class,
            description: HttpFoundationResponse::$statusTexts[$result->status] ?? sprintf('HTTP %d', $result->status),
            action: $action,
            // A literal body is route-specific; a status-only descriptor (no body) is shareable.
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
