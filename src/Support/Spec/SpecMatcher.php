<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Spec;

use Illuminate\Container\Attributes\Scoped;
use Radiergummi\OpenApi\Support\Inclusion\InclusionEvaluator;

use function array_any;
use function fnmatch;
use function is_array;
use function str_contains;
use function str_starts_with;

/**
 * Evaluates a {@see SpecDefinition::$match} config block against a single route.
 *
 * Pure: no Laravel container access, no reflection. Stateless. Three supported keys:
 *
 * - `prefix`     — string|string[]: URI glob(s); matches if any matches via {@see fnmatch()}.
 * - `middleware` — string|string[]: middleware token(s); matches if any of the route's
 *                  middleware entries equals the token OR shares the token's pre-`:` prefix
 *                  (so `'auth'` matches `'auth:api'`).
 * - `namespace`  — string|string[]: controller FQCN prefix(es); matches if the controller's
 *                  class name starts with any prefix. Closure routes (controller === null)
 *                  never match a namespace constraint.
 *
 * AND across the three keys (every present key must match); OR within a single key's array.
 *
 * An empty/missing match block matches nothing — the higher-level catch-all semantics for the
 * implicit `default` spec live in {@see InclusionEvaluator}, which short-circuits before
 * reaching the matcher. This keeps {@see SpecMatcher} a pure predicate over the three config
 * keys.
 */
#[Scoped]
final readonly class SpecMatcher
{
    /**
     * @param array<array-key, string> $middleware Resolved middleware tokens for the route; keys are ignored.
     * @param array<string, mixed>     $match
     */
    public function matches(
        string $uri,
        array $middleware,
        ?string $controller,
        array $match,
    ): bool {
        if ($match === []) {
            return false;
        }

        if (isset($match['prefix']) && !$this->matchesPrefix($uri, $match['prefix'])) {
            return false;
        }

        if (isset($match['middleware']) && !$this->matchesMiddleware($middleware, $match['middleware'])) {
            return false;
        }

        if (isset($match['namespace']) && !$this->matchesNamespace($controller, $match['namespace'])) {
            return false;
        }

        return true;
    }

    private function matchesPrefix(string $uri, mixed $patterns): bool
    {
        $list = is_array($patterns) ? $patterns : [$patterns];

        return array_any($list, static fn(string $pattern): bool => fnmatch($pattern, $uri));
    }

    /**
     * @param array<array-key, string> $middleware
     */
    private function matchesMiddleware(array $middleware, mixed $tokens): bool
    {
        $list = is_array($tokens) ? $tokens : [$tokens];

        foreach ($list as $token) {
            foreach ($middleware as $entry) {
                if ($entry === $token) {
                    return true;
                }

                // If the token has no colon, allow it to match any colon-suffixed variant.
                // 'auth' matches 'auth:api', 'auth:partner'. But 'auth:partner' must match exactly.
                if (!str_contains($token, ':') && str_starts_with($entry, $token . ':')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function matchesNamespace(?string $controller, mixed $prefixes): bool
    {
        if ($controller === null) {
            return false;
        }

        $list = is_array($prefixes) ? $prefixes : [$prefixes];

        return array_any($list, static fn(string $prefix): bool => str_starts_with($controller, $prefix));
    }
}
