<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Routing\RouteMiddlewareGatherer;

use function array_filter;
use function implode;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Reports when a controller method or class is marked `#[PublicEndpoint]` but the route still
 * carries `auth:*` or `scope:*` middleware, indicating a contradiction between the declared
 * intent and the actual middleware stack.
 */
final readonly class PublicEndpointContradictsMiddleware implements Rule, OperationRuleVisitor
{
    public function __construct(
        private RouteMiddlewareGatherer $middlewareGatherer,
    ) {}

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->webhook) {
            return;
        }

        if ($operation->descriptor === null) {
            return;
        }

        if (!$operation->hasPublicEndpointAttribute()) {
            return;
        }

        $descriptor = $operation->descriptor;
        $conflicting = $this->findConflictingMiddleware($descriptor);

        if ($conflicting === []) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '%s::%s() is marked #[PublicEndpoint] but the route has conflicting middleware: %s',
                $descriptor->controller?->getShortName() ?? '(unknown)',
                $descriptor->method?->getName() ?? '(unknown)',
                implode(', ', $conflicting),
            ),
            fixHint: 'Remove the #[PublicEndpoint] attribute or remove the auth/scope middleware from the route.',
        );
    }

    /**
     * @return list<string>
     */
    private function findConflictingMiddleware(ActionDescriptor $descriptor): array
    {
        // The gathered list may contain closure middleware; filter to strings before matching.
        $middleware = array_filter(
            $this->middlewareGatherer->middlewareFor($descriptor->route),
            is_string(...),
        );
        $conflicting = [];

        foreach ($middleware as $mw) {
            if (
                str_starts_with($mw, 'auth:')
                || str_starts_with($mw, 'scope:')
                || str_starts_with($mw, 'scopes:')
            ) {
                $conflicting[] = $mw;
            }
        }

        return $conflicting;
    }

    #[Override]
    public function id(): string
    {
        return 'publicendpoint.contradicts-middleware';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return '#[PublicEndpoint] is present but the route has auth/scope middleware.';
    }
}
