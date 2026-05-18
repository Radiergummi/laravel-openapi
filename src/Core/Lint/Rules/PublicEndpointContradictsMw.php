<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;

use function implode;
use function sprintf;
use function str_starts_with;

/**
 * Reports when a controller method or class is marked `#[PublicEndpoint]` but
 * the route still carries `auth:*` or `scope:*` middleware, indicating a
 * contradiction between the declared intent and the actual middleware stack.
 */
final class PublicEndpointContradictsMw implements Rule, OperationRuleVisitor
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

        if (!$operation->hasPublicEndpointAttribute()) {
            return;
        }

        $conflicting = $this->findConflictingMiddleware($operation);

        if ($conflicting === []) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '%s::%s() is marked #[PublicEndpoint] but the route has conflicting middleware: %s',
                $operation->descriptor->controller?->getShortName() ?? '(unknown)',
                $operation->descriptor->method?->getName() ?? '(unknown)',
                implode(', ', $conflicting),
            ),
            fixHint: 'Remove the #[PublicEndpoint] attribute or remove the auth/scope middleware from the route.',
        );
    }

    /**
     * Return the list of middleware on the route that conflict with a public
     * endpoint declaration.
     *
     * @return list<string>
     */
    private function findConflictingMiddleware(OperationNode $operation): array
    {
        $middleware = $operation->descriptor->route->middleware();
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
