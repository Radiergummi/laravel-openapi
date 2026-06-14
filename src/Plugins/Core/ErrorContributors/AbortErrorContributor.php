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
use function array_search;
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
     * The whitelisted helpers, mapped to their parameter names in declared order. The position of
     * `code` is the status argument and `message` follows it; `abort_if` / `abort_unless` carry a
     * leading `boolean` condition that shifts both by one. The names let a named-argument call
     * (`abort(code: 403)`) resolve to the same position as a positional one.
     */
    private const array HELPER_PARAMETERS = [
        'abort' => ['code', 'message'],
        'abort_if' => ['boolean', 'code', 'message'],
        'abort_unless' => ['boolean', 'code', 'message'],
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

        if (!array_key_exists($name, self::HELPER_PARAMETERS)) {
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
        $parameters = self::HELPER_PARAMETERS[$helper];
        $arguments = $call->getArgs();

        $status = $this->literalArgument($arguments, $parameters, 'code');

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

        [$description, $authored] = $this->description($arguments, $parameters, $status);

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
     * Evaluates the helper's `$parameterName` argument as a compile-time literal, resolving it
     * either by name (`abort(code: 403)`) or by its declared position (`abort(403)`). Returns null
     * when the argument is absent, unpacked, or not a literal.
     *
     * @param array<int, Arg> $arguments
     * @param list<string>    $parameters the helper's parameter names in declared order
     */
    private function literalArgument(array $arguments, array $parameters, string $parameterName): mixed
    {
        $argument = $this->resolveArgument($arguments, $parameters, $parameterName);

        if ($argument === null || $argument->unpack) {
            return null;
        }

        return $this->literalValueOf($argument->value);
    }

    /**
     * Finds the argument bound to `$parameterName`: a matching named argument wins, otherwise the
     * positional argument at the parameter's declared index — but only while no named argument has
     * appeared (named arguments may not precede positional ones in a valid call).
     *
     * @param array<int, Arg> $arguments
     * @param list<string>    $parameters the helper's parameter names in declared order
     */
    private function resolveArgument(array $arguments, array $parameters, string $parameterName): ?Arg
    {
        foreach ($arguments as $argument) {
            if ($argument->name !== null && $argument->name->toString() === $parameterName) {
                return $argument;
            }
        }

        $index = array_search($parameterName, $parameters, true);
        $positional = $index === false ? null : ($arguments[$index] ?? null);

        return $positional !== null && $positional->name === null ? $positional : null;
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
     * @param list<string>    $parameters the helper's parameter names in declared order
     *
     * @return array{0: string, 1: bool} the description and whether it is route-authored
     */
    private function description(array $arguments, array $parameters, int $status): array
    {
        $message = $this->literalArgument($arguments, $parameters, 'message');

        if (is_string($message) && $message !== '') {
            return [$message, true];
        }

        return [HttpFoundationResponse::$statusTexts[$status] ?? sprintf('HTTP %d', $status), false];
    }

    // endregion
}
