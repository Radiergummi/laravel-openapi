<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Routing\RouteMiddlewareGatherer;

use function array_any;
use function array_filter;
use function is_array;
use function is_string;
use function Radiergummi\OpenApi\is_undefined;
use function sprintf;
use function str_starts_with;

/**
 * Reports when a route has auth/scope middleware but the operation declares no `security`
 * requirement. Fires only when `security` is `UNDEFINED` (not even an explicit empty array)
 * and the action lacks `#[PublicEndpoint]`.
 */
final class OperationSecurityMissing implements Rule, OperationRuleVisitor
{
    public string $id = 'operation.security-missing';
    public Severity $severity = Severity::Degraded;
    public string $description = 'Route enforces auth middleware but the operation declares no security, implying the endpoint is public.';

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

        if (!$this->hasAuthMiddleware($operation->descriptor)) {
            return;
        }

        if ($this->hasSecurityDeclared($operation)) {
            return;
        }

        if ($operation->hasPublicEndpointAttribute()) {
            return;
        }

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                '%s::%s() has auth/scope middleware but declares no security requirement in the spec.',
                $operation->descriptor->controller?->getShortName() ?? '(unknown)',
                $operation->descriptor->method?->getName() ?? '(unknown)',
            ),
            fixHint: 'Add a scope:/auth security requirement to this operation, or mark the endpoint #[PublicEndpoint] if it is intentionally public.',
        );
    }

    /** Filters to strings because the gathered middleware list may contain closures. */
    private function hasAuthMiddleware(ActionDescriptor $descriptor): bool
    {
        $middleware = array_filter(
            $this->middlewareGatherer->middlewareFor($descriptor->route),
            is_string(...),
        );

        return array_any(
            $middleware,
            static fn(string $entry): bool
                => str_starts_with($entry, 'auth:')
                || str_starts_with($entry, 'scope:')
                || str_starts_with($entry, 'scopes:')
                || str_starts_with($entry, 'abilities:')
                || str_starts_with($entry, 'ability:'),
        );
    }

    /** `UNDEFINED` means the key was never written; an explicit empty array is a public signal. */
    private function hasSecurityDeclared(OperationNode $operation): bool
    {
        $security = $operation->raw->security;

        if (is_undefined($security)) {
            return false;
        }

        return is_array($security);
    }



}
