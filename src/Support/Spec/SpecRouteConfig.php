<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Spec;

use function array_key_exists;
use function sprintf;

/**
 * Resolves a spec's `route_uri` / `playground_uri` from root defaults merged with per-spec
 * overrides. `false`/`null` opts out of HTTP serving (returns `false`).
 *
 * @internal
 */
final readonly class SpecRouteConfig
{
    public function __construct(
        private string $rootRouteUri = 'openapi.yaml',
        private string $rootPlaygroundUri = 'docs',
    ) {}

    /**
     * @param array<string, mixed> $overrides
     */
    public function routeUri(string $specName, array $overrides): false|string
    {
        return $this->resolve(
            $overrides,
            'route_uri',
            $specName === 'default'
                ? $this->rootRouteUri
                : sprintf('openapi-%s.yaml', $specName),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function resolve(array $overrides, string $key, string $default): false|string
    {
        if (!array_key_exists($key, $overrides)) {
            return $default;
        }

        $value = $overrides[$key];

        if ($value === false || $value === null) {
            return false;
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function playgroundUri(string $specName, array $overrides): false|string
    {
        return $this->resolve(
            $overrides,
            'playground_uri',
            $specName === 'default'
                ? $this->rootPlaygroundUri
                : sprintf('docs/%s', $specName),
        );
    }
}
