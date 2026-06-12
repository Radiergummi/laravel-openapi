<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\ErrorContributors;

use Illuminate\Container\Attributes\Scoped;
use Override;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\MethodBody\AstLiteralEvaluator;
use Radiergummi\OpenApi\Support\MethodBody\ConditionalContextPolicy;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\NonLiteralValueException;
use Radiergummi\OpenApi\Support\MethodBody\StatementNodeFinder;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function array_key_exists;
use function function_exists;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Infers error responses from `abort()`, `abort_if()`, and `abort_unless()` calls in the
 * controller method body — a Tier-1 bounded scan (epic #5).
 *
 * Scans the first {@see self::STATEMENT_LIMIT} top-level statements under
 * {@see ConditionalContextPolicy::IncludeConditionalContexts}: an `abort(403)` inside an `if`
 * guard is exactly the outcome worth documenting. The literal integer status becomes the response
 * status; a literal string message becomes its description (otherwise the standard reason phrase
 * is used). The condition argument of `abort_if` / `abort_unless` is never analysed.
 *
 * Degradation contract: a matched call whose status is not a compile-time integer literal is
 * skipped with a generation-log note (`#[Response]` is the escape hatch); a literal status outside
 * 400–599 (e.g. `abort(302)` redirects) is silently out of scope. Explicit `#[Response]`
 * attributes win in the stage that drives the contributor chain — not re-implemented here.
 */
#[Scoped]
final readonly class AbortErrorContributor implements ErrorResponseContributor
{
    public const int STATEMENT_LIMIT = 10;

    /**
     * The whitelisted helpers, mapped to the position of their status argument (the message
     * argument follows directly after).
     */
    private const array HELPER_STATUS_ARGUMENT = [
        'abort' => 0,
        'abort_if' => 1,
        'abort_unless' => 1,
    ];

    private StatementNodeFinder $statementNodeFinder;

    public function __construct(
        private MethodBodyScanner $scanner,
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
            fn(Node $node): bool => $node instanceof FuncCall && $this->helperName($node) !== null,
        );

        $descriptors = [];

        foreach ($calls as $call) {
            if (!$call instanceof FuncCall) {
                continue;
            }

            $errorDescriptor = $this->descriptorFromCall($call, $descriptor, $method);

            if ($errorDescriptor !== null) {
                $descriptors[] = $errorDescriptor;
            }
        }

        return $descriptors;
    }

    // region Call-shape matching

    /**
     * Returns the whitelisted helper name the call resolves to, or null when it is not one.
     *
     * Names arrive resolved by the scanner's NameResolver pass. A fully-qualified name matches
     * when it is the root-namespace helper itself (`\abort`); an import aliasing some other
     * function resolves to that function's FQCN and falls through. An *unqualified* name in a
     * namespaced file stays unresolved (PHP's runtime fallback), so it matches as Laravel's
     * global helper — unless a same-namespace function of that name is actually defined, in
     * which case PHP would call the user's function and we must not document it.
     */
    private function helperName(FuncCall $call): ?string
    {
        if ($call->isFirstClassCallable() || !$call->name instanceof Name) {
            return null;
        }

        $name = $call->name->toLowerString();

        if (!array_key_exists($name, self::HELPER_STATUS_ARGUMENT)) {
            return null;
        }

        if ($call->name->isFullyQualified()) {
            return $name;
        }

        $namespacedName = $call->name->getAttribute('namespacedName');

        if ($namespacedName instanceof Name && function_exists($namespacedName->toString())) {
            return null;
        }

        return $name;
    }

    // endregion

    // region Descriptor construction

    private function descriptorFromCall(
        FuncCall $call,
        ActionDescriptor $action,
        ReflectionMethod $method,
    ): ?ErrorDescriptor {
        /** @var string $helper guaranteed by the find predicate */
        $helper = $this->helperName($call);
        $statusIndex = self::HELPER_STATUS_ARGUMENT[$helper];
        $arguments = $call->getArgs();

        $status = $this->literalValueAt($arguments, $statusIndex);

        if (!is_int($status)) {
            // Abort-shaped call found, but the status is not statically readable — note it.
            $this->logger->notice(
                sprintf(
                    '%s() call in %s::%s has no statically readable status code; no error response '
                    . 'inferred. Annotate the action with #[Response] to document it.',
                    $helper,
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                ),
            );

            return null;
        }

        if ($status < 400 || $status > 599) {
            // Readable, but not an error status (e.g. an abort(302) redirect) — out of scope.
            return null;
        }

        [$description, $authored] = $this->description($arguments, $statusIndex + 1, $status);

        return new ErrorDescriptor(
            status: $status,
            // Mirror what abort() actually throws, so envelope resolvers can branch on it.
            exceptionClass: $status === 404 ? NotFoundHttpException::class : HttpException::class,
            description: $description,
            action: $action,
            // An authored message is specific to this route and must not be hoisted into the
            // shared per-status response component.
            shareableDescription: !$authored,
        );
    }

    /**
     * Evaluates the positional argument at the given index as a compile-time literal. Returns
     * null when the argument is absent, named, unpacked, or not a literal.
     *
     * @param array<int, Arg> $arguments
     */
    private function literalValueAt(array $arguments, int $index): mixed
    {
        $argument = $arguments[$index] ?? null;

        if ($argument === null || $argument->name !== null || $argument->unpack) {
            return null;
        }

        return $this->literalValueOf($argument->value);
    }

    private function literalValueOf(Expr $expression): mixed
    {
        try {
            return AstLiteralEvaluator::evaluate($expression);
        } catch (NonLiteralValueException) {
            return null;
        }
    }

    /**
     * The literal string message when present, otherwise the standard reason phrase. A dynamic
     * message does not discard the response — the status is still a fact worth documenting.
     *
     * @param array<int, Arg> $arguments
     *
     * @return array{0: string, 1: bool} the description and whether it is route-authored
     */
    private function description(array $arguments, int $messageIndex, int $status): array
    {
        $message = $this->literalValueAt($arguments, $messageIndex);

        if (is_string($message) && $message !== '') {
            return [$message, true];
        }

        return [HttpFoundationResponse::$statusTexts[$status] ?? sprintf('HTTP %d', $status), false];
    }

    // endregion
}
