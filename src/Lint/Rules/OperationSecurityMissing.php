<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function is_array;
use function sprintf;

/**
 * Reports when a route has auth or scope middleware but the generated operation declares no
 * `security` requirement, implying the endpoint is public while the runtime enforces
 * authentication. The forward mirror of {@see PublicEndpointContradictsMw}.
 *
 * The rule fires only when ALL of the following hold:
 *  1. The route carries `auth:*`, `scope:*`, or `scopes:*` middleware.
 *  2. The operation's `security` field is `UNDEFINED` (never set — not even an explicit empty
 *     array which signals a intentional public override).
 *  3. The controller method or class is NOT marked `#[PublicEndpoint]`.
 */
final class OperationSecurityMissing implements Rule, OperationRuleVisitor
{
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

        if (!$operation->hasAuthMiddleware()) {
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

        if ($security === Generator::UNDEFINED) {
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
