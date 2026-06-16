<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\ErrorContributors;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Override;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\MethodBody\ConditionalContextPolicy;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\StatementNodeFinder;

use function in_array;

/**
 * Infers a 404 from a `findOrFail()` / `firstOrFail()` call in the controller body.
 *
 * Uses the same `config('openapi.exception_responses')[ModelNotFoundException::class]` entry as
 * `RouteModelBindingErrorContributor`, so the emitted response is identical and dedup is
 * order-independent.
 */
#[Scoped]
final readonly class FindOrFailErrorContributor implements ErrorResponseContributor
{
    public const int STATEMENT_LIMIT = 10;

    private const array THROWING_METHODS = ['findorfail', 'firstorfail'];

    private StatementNodeFinder $statementNodeFinder;

    /**
     * @param array<string, array{status: int, description: string}> $exceptionMap
     */
    public function __construct(
        private MethodBodyScanner $scanner,
        #[Config('openapi.exception_responses', default: [])]
        private array $exceptionMap = [],
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

        $entry = $this->exceptionMap[ModelNotFoundException::class] ?? null;

        if ($entry === null) {
            return [];
        }

        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);

        $match = $this->statementNodeFinder->findFirst(
            $statements,
            ConditionalContextPolicy::IncludeConditionalContexts,
            fn(Node $node): bool => $this->isThrowingLookup($node),
        );

        if ($match === null) {
            return [];
        }

        return [
            new ErrorDescriptor(
                status: (int) $entry['status'],
                exceptionClass: ModelNotFoundException::class,
                description: (string) $entry['description'],
                action: $descriptor,
            ),
        ];
    }

    private function isThrowingLookup(Node $node): bool
    {
        if ($node instanceof MethodCall || $node instanceof StaticCall) {
            // Method names are case-insensitive; compare lowercase.
            return $node->name instanceof Identifier
                && in_array($node->name->toLowerString(), self::THROWING_METHODS, true);
        }

        return false;
    }
}
