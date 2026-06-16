<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use Illuminate\Container\Attributes\Scoped;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use Radiergummi\OpenApi\Enums\PaginatorKind;
use Radiergummi\OpenApi\Support\MethodBody\ConditionalContextPolicy;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\StatementNodeFinder;
use ReflectionMethod;

/**
 * Detects a `paginate()`-family call in a controller body to determine the paginator kind.
 * Only unconditional top-level calls are matched; branches are skipped intentionally.
 *
 * @internal
 */
#[Scoped]
final readonly class PaginatorCallReader
{
    private const int STATEMENT_LIMIT = 10;

    private StatementNodeFinder $statementNodeFinder;

    public function __construct(private MethodBodyScanner $scanner)
    {
        $this->statementNodeFinder = new StatementNodeFinder();
    }

    public function detect(ReflectionMethod $method): ?PaginatorKind
    {
        $statements = $this->scanner->firstStatements($method, self::STATEMENT_LIMIT);

        $call = $this->statementNodeFinder->findFirst(
            $statements,
            ConditionalContextPolicy::SkipConditionalContexts,
            static function (Node $node): bool {
                $name = match (true) {
                    $node instanceof MethodCall,
                    $node instanceof StaticCall => $node->name,
                    default => null,
                };

                return $name instanceof Identifier
                    && PaginatorKind::fromPaginatingMethod($name->toString()) !== null;
            },
        );

        if (!$call instanceof MethodCall && !$call instanceof StaticCall) {
            return null;
        }

        // Re-check for static analysis; the predicate already guarantees Identifier.
        return $call->name instanceof Identifier
            ? PaginatorKind::fromPaginatingMethod($call->name->toString())
            : null;
    }
}
