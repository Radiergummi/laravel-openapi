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
 * Detects a `paginate()`-family call in a controller action body — the Tier-1 bounded scan behind
 * pagination query parameters (issue #31).
 *
 * Scans the first {@see self::STATEMENT_LIMIT} top-level statements under
 * {@see ConditionalContextPolicy::SkipConditionalContexts}: the first method or static call whose
 * lowercased name maps via {@see PaginatorKind::fromPaginatingMethod()} decides the kind. Detection
 * is presence-based (any unconditional statement, not only the returned expression), since in a
 * listing action the paginate call is the operation's shape rather than a guarded branch — so a
 * call hidden behind an `if`/ternary is not matched. Anything else (no paginate call, an unreadable
 * or closure body) returns null and the operation advertises no pagination knob.
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

        // The predicate only matches an Identifier name, but its narrowing is invisible here.
        return $call->name instanceof Identifier
            ? PaginatorKind::fromPaginatingMethod($call->name->toString())
            : null;
    }
}
