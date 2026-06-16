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
 * controller body. Scans the first {@see self::STATEMENT_LIMIT} statements including conditional
 * contexts. Non-literal statuses are skipped with a log note; statuses outside 400-599 are
 * ignored. Use `#[Response]` when inference is insufficient.
 */
#[Scoped]
final readonly class AbortErrorContributor implements ErrorResponseContributor
{
    public const int STATEMENT_LIMIT = 10;

    /** Parameters in declared order; `abort_if`/`abort_unless` have a leading `boolean` arg. */
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
     * Returns the whitelisted helper name, or null. A same-namespace function of the same name
     * takes precedence at runtime, so we skip unqualified calls when one is defined.
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
            return null;
        }

        [$description, $authored] = $this->description($arguments, $parameters, $status);

        return new ErrorDescriptor(
            status: $status,
            exceptionClass: $status === 404 ? NotFoundHttpException::class : HttpException::class,
            description: $description,
            action: $action,
            // An authored message is route-specific; don't hoist it into a shared component.
            shareableDescription: !$authored,
        );
    }

    /**
     * Evaluates an argument as a compile-time literal (by name or position). Returns null when
     * absent, unpacked, or non-literal.
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
     * Resolves an argument by name first, then by positional index.
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
     * Returns the literal message when present, otherwise the standard reason phrase.
     * A dynamic message does not discard the response.
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
