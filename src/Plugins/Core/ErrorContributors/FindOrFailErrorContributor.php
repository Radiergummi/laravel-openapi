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
 * Infers a 404 Not Found error response from a `findOrFail()` / `firstOrFail()` lookup in the
 * controller method body — a Tier-1 bounded scan (epic #5).
 *
 * Both `Model::findOrFail()` (static) and `$query->firstOrFail()` (instance) throw a
 * {@see ModelNotFoundException} (→ 404 framework handler) when the record is absent. The presence
 * of the call is the entire signal: there is no argument to evaluate, so — unlike
 * {@see AbortErrorContributor} — there is no "matched but unreadable" degrade path. A single 404
 * covers the whole action; the framework throws the same exception regardless of which lookup
 * fails.
 *
 * Scans the first {@see self::STATEMENT_LIMIT} top-level statements under
 * {@see ConditionalContextPolicy::IncludeConditionalContexts}, so a lookup inside an `if` guard
 * still counts. The status and description come from
 * `config('openapi.exception_responses')[ModelNotFoundException::class]` — the same entry
 * {@see RouteModelBindingErrorContributor} uses, so the emitted 404 is byte-identical and the
 * stage's first-contributor-wins dedup is order-independent.
 */
#[Scoped]
final readonly class FindOrFailErrorContributor implements ErrorResponseContributor
{
    public const int STATEMENT_LIMIT = 10;

    private const array THROWING_METHODS = ['findOrFail', 'firstOrFail'];

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

    /**
     * Whether the node is a `findOrFail()` / `firstOrFail()` call in either the static
     * (`Model::findOrFail()`) or instance (`$query->firstOrFail()`) form.
     */
    private function isThrowingLookup(Node $node): bool
    {
        if ($node instanceof MethodCall || $node instanceof StaticCall) {
            return $node->name instanceof Identifier
                && in_array($node->name->toString(), self::THROWING_METHODS, true);
        }

        return false;
    }
}
