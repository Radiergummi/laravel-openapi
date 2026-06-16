<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Spec;

use function array_key_exists;
use function sprintf;

/**
 * Resolves a single spec's served `route_uri` / `playground_uri` from the root `openapi.routes`
 * defaults plus that spec's per-spec overrides.
 *
 * The convention is shared by two call sites — {@see SpecRegistry::buildSpec()} (which feeds the
 * resolved URIs into {@see SpecDefinition}) and the service provider's route mounting — so it lives
 * here as the single source of truth. The rules:
 *
 *   - No override key present: the default applies. For the `default` spec that is the root
 *     `routes.spec.uri` / `routes.playground.uri`; for a named spec it is `openapi-{name}.yaml` /
 *     `docs/{name}`.
 *   - An explicit `false` or `null` override opts the spec out of HTTP serving (returns `false`).
 *   - Any other override value is cast to a string and used verbatim.
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
