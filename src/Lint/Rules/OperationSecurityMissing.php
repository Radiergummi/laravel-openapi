<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
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
 * Reports when a route has auth or scope middleware but the generated operation declares no
 * `security` requirement, implying the endpoint is public while the runtime enforces
 * authentication. The forward mirror of {@see PublicEndpointContradictsMiddleware}.
 *
 * The rule fires only when ALL of the following hold:
 *  1. The route carries `auth:*`, `scope:*`, or `scopes:*` middleware.
 *  2. The operation's `security` field is `UNDEFINED` (never set — not even an explicit empty
 *     array which signals a intentional public override).
 *  3. The controller method or class is NOT marked `#[PublicEndpoint]`.
 */
final readonly class OperationSecurityMissing implements Rule, OperationRuleVisitor
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
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '%s::%s() has auth/scope middleware but declares no security requirement in the spec.',
                $operation->descriptor->controller?->getShortName() ?? '(unknown)',
                $operation->descriptor->method?->getName() ?? '(unknown)',
            ),
            fixHint: 'Add a scope:/auth security requirement to this operation, or mark the endpoint #[PublicEndpoint] if it is intentionally public.',
        );
    }

    /**
     * Whether the route carries any `auth:*`, `scope:*`, `scopes:*`, or Sanctum `abilities:*` /
     * `ability:*` middleware. Reads the gathered (controller-aware) list, matching the generator;
     * filters to strings since the gathered list may contain closure middleware.
     */
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

    /**
     * Returns true when the operation has `security` set in the raw annotation — either an
     * explicit empty array (`security: []`, the OpenAPI "public" signal emitted by
     * `#[PublicEndpoint]`) or a non-empty list of requirements.
     *
     * `Generator::UNDEFINED` means the property was never written, i.e. the spec has no
     * `security` key for this operation.
     */
    private function hasSecurityDeclared(OperationNode $operation): bool
    {
        $security = $operation->raw->security;

        if (is_undefined($security)) {
            return false;
        }

        return is_array($security);
    }

    #[Override]
    public function id(): string
    {
        return 'operation.security-missing';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'Route enforces auth middleware but the operation declares no security, implying the endpoint is public.';
    }
}
