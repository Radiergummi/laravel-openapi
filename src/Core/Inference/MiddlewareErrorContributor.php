<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Inference;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Override;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Extraction\DetectsAuthMiddleware;
use Throwable;

use function array_any;
use function array_values;
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
        $middleware = array_values($descriptor->route->gatherMiddleware());

        foreach (['auth', 'scope', 'throttle'] as $kind) {
            if (!isset($this->middlewareMap[$kind])) {
                continue;
            }

            $detected = match ($kind) {
                'auth'     => $this->hasAuthMiddleware($middleware),
                'scope'    => $this->hasScopeMiddleware($middleware),
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
            );
        }

        return $descriptors;
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
    private function hasThrottleMiddleware(array $middleware): bool
    {
        return array_any(
            $middleware,
            static fn(string $entry): bool => $entry === 'throttle' || str_starts_with($entry, 'throttle:'),
        );
    }
}
