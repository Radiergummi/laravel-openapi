<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\ErrorContributors;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Override;
use Radiergummi\OpenApi\Attributes\PublicEndpoint;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Extraction\DetectsAuthMiddleware;
use Radiergummi\OpenApi\Support\Routing\RouteMiddlewareGatherer;
use Throwable;

use function array_any;
use function array_filter;
use function array_values;
use function is_string;
use function str_starts_with;

/**
 * Infers error responses from a route's middleware stack.
 *
 * Checks for `auth`, `scope`, and `throttle` middleware entries and emits the corresponding
 * {@see ErrorDescriptor}s based on `config('openapi.middleware_responses')`.
 */
#[Scoped]
final readonly class MiddlewareErrorContributor implements ErrorResponseContributor
{
    use DetectsAuthMiddleware;

    /**
     * @param array<string, array{status: int, description: string, exception?: class-string<Throwable>}> $middlewareMap
     */
    public function __construct(
        private RouteMiddlewareGatherer $middlewareGatherer,
        #[Config('openapi.middleware_responses', default: [])]
        private array $middlewareMap = [],
    ) {}

    /**
     * @return list<ErrorDescriptor>
     */
    #[Override]
    public function contribute(ActionDescriptor $descriptor): array
    {
        $descriptors = [];
        // The gathered list may include closure middleware; the string-typed
        // detectors below only understand named middleware, so drop the rest.
        $middleware = array_values(
            array_filter(
                $this->middlewareGatherer->middlewareFor($descriptor->route),
                is_string(...),
            ),
        );

        // A declared-public operation (#[PublicEndpoint]) clears `security`, so the responses that
        // mirror it — the auth-derived 401 and the scope-derived 403 — must not be emitted, or the
        // document would document a 401 on an affirmatively-public endpoint (#259). `can`
        // (authorization, not part of `security`) and `throttle` (rate limiting) are independent
        // and stay.
        $declaredPublic = $this->isDeclaredPublic($descriptor);

        foreach (['auth', 'scope', 'can', 'throttle'] as $kind) {
            if (!isset($this->middlewareMap[$kind])) {
                continue;
            }

            if ($declaredPublic && ($kind === 'auth' || $kind === 'scope')) {
                continue;
            }

            $detected = match ($kind) {
                'auth' => $this->hasAuthMiddleware($middleware),
                'scope' => $this->hasScopeMiddleware($middleware),
                'can' => $this->hasCanMiddleware($middleware),
                'throttle' => $this->hasThrottleMiddleware($middleware),
            };

            if (!$detected) {
                continue;
            }

            $entry = $this->middlewareMap[$kind];
            $descriptors[] = new ErrorDescriptor(
                status: (int) $entry['status'],
                exceptionClass: $entry['exception'] ?? null,
                description: (string) $entry['description'],
                action: $descriptor,
            );
        }

        return $descriptors;
    }

    /**
     * Whether the operation is declared affirmatively public via {@see PublicEndpoint} on the
     * action method or its controller — the same detection {@see OperationBuilder::resolveSecurity()}
     * uses to clear `security`.
     */
    private function isDeclaredPublic(ActionDescriptor $descriptor): bool
    {
        return $descriptor->actionAttributes(PublicEndpoint::class) !== []
            || $descriptor->controllerAttributes(PublicEndpoint::class) !== [];
    }

    /**
     * @param list<string> $middleware
     */
    private function hasScopeMiddleware(array $middleware): bool
    {
        return array_any(
            $middleware,
            static fn(string $entry): bool
                => str_starts_with($entry, 'scope:')
                || str_starts_with($entry, 'scopes:'),
        );
    }

    /**
     * @param list<string> $middleware
     */
    private function hasCanMiddleware(array $middleware): bool
    {
        return array_any(
            $middleware,
            static fn(string $entry): bool => str_starts_with($entry, 'can:'),
        );
    }

    /**
     * @param list<string> $middleware
     */
    private function hasThrottleMiddleware(array $middleware): bool
    {
        return array_any(
            $middleware,
            static fn(string $entry): bool => $entry === 'throttle' || str_starts_with($entry, 'throttle:'),
        );
    }
}
