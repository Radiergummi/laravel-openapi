<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Spec;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use InvalidArgumentException;
use OpenApi\Annotations as OA;

use function array_key_exists;
use function array_map;
use function array_merge;
use function array_values;
use function is_array;
use function sprintf;
use function storage_path;

/**
 * Materialises {@see SpecDefinition} value objects from the application's
 * `config('openapi.*')` keys plus the optional `config('openapi.specs')` map.
 *
 * The default spec is implicit: it is always present and built from root keys
 * (`info`, `servers`, `tags`, `output_path`, `routes.spec.uri`, `routes.playground.uri`).
 * An explicit `'default'` entry in `'specs'` may add a `match` config or override any of
 * the per-spec fields; missing keys fall back to root.
 *
 * Resolved lazily on first call and memoised for the lifetime of the registry
 * instance (which is bound `scoped` by the service provider — one instance per
 * generation run / per request).
 */
#[Scoped]
final class SpecRegistry
{
    /** @var null|array<string, SpecDefinition> */
    private ?array $cache = null;

    /** @var array<string, mixed> */
    private readonly array $rootInfo;

    /** @var list<array<string, mixed>> */
    private readonly array $rootServers;

    /** @var null|array<string, array<string, mixed>> */
    private readonly ?array $specs;

    private readonly string $storagePath;

    /**
     * @param array<string, mixed>       $rootInfo
     * @param list<array<string, mixed>> $rootServers
     * @param array<string, mixed>       $rootTags
     */
    public function __construct(
        #[Config('openapi.info', [])]
        array $rootInfo = [],
        #[Config('app.name', 'API')]
        string $appName = 'API',
        #[Config('openapi.servers', [])]
        array $rootServers = [],
        #[Config('openapi.tags', [])]
        private readonly array $rootTags = [],
        #[Config('openapi.output_path', '')]
        private readonly string $rootOutputPath = '',
        #[Config('openapi.routes.spec.uri', 'openapi.yaml')]
        private readonly string $rootRouteUri = 'openapi.yaml',
        #[Config('openapi.routes.playground.uri', 'docs')]
        private readonly string $rootPlaygroundUri = 'docs',
        #[Config('openapi.specs')]
        mixed $specs = null,
        ?string $storagePath = null,
    ) {
        $rootInfo['title'] ??= $appName;
        $rootInfo['version'] ??= '0.0.0';

        $this->rootInfo = $rootInfo;
        $this->rootServers = array_values($rootServers);
        $this->specs = is_array($specs) ? $specs : null;
        $this->storagePath = $storagePath ?? storage_path();
    }

    /** @return list<SpecDefinition> */
    public function all(): array
    {
        return array_values($this->resolve());
    }

    public function get(string $name): SpecDefinition
    {
        $map = $this->resolve();

        if (!array_key_exists($name, $map)) {
            throw new InvalidArgumentException(sprintf("Spec '%s' is not defined.", $name));
        }

        return $map[$name];
    }

    public function default(): SpecDefinition
    {
        return $this->get('default');
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->resolve());
    }

    /** @return array<string, SpecDefinition> */
    private function resolve(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $defaultOverrides = $this->specs['default'] ?? [];
        $defs = ['default' => $this->buildSpec('default', $defaultOverrides)];

        foreach (($this->specs ?? []) as $name => $overrides) {
            if ($name === 'default') {
                continue;
            }

            $defs[(string) $name] = $this->buildSpec((string) $name, (array) $overrides);
        }

        return $this->cache = $defs;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function buildSpec(string $name, array $overrides): SpecDefinition
    {
        $infoArray = is_array($overrides['info'] ?? null)
            ? array_merge($this->rootInfo, $overrides['info'])
            : $this->rootInfo;

        $serversArray = is_array($overrides['servers'] ?? null) ? $overrides['servers'] : $this->rootServers;
        $tagsArray = is_array($overrides['tags'] ?? null) ? $overrides['tags'] : $this->rootTags;

        $servers = array_values(array_map(
            static fn(array $s): OA\Server => new OA\Server($s),
            $serversArray,
        ));

        $tags = [];

        foreach ($tagsArray as $tagName => $cfg) {
            $cfg = is_array($cfg) ? $cfg : [];
            $tags[] = new OA\Tag(['name' => (string) $tagName] + $cfg);
        }

        $match = is_array($overrides['match'] ?? null) ? $overrides['match'] : [];

        $outputPath = $this->resolveOutputPath($name, $overrides);
        $routeUri = $this->resolveOptional($overrides, 'route_uri', $name === 'default'
            ? $this->rootRouteUri
            : sprintf('openapi-%s.yaml', $name));
        $playgroundUri = $this->resolveOptional($overrides, 'playground_uri', $name === 'default'
            ? $this->rootPlaygroundUri
            : sprintf('docs/%s', $name));

        return new SpecDefinition(
            name: $name,
            info: new OA\Info($infoArray),
            servers: $servers,
            tags: $tags,
            match: $match,
            outputPath: $outputPath,
            routeUri: $routeUri,
            playgroundUri: $playgroundUri,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function resolveOutputPath(string $name, array $overrides): string
    {
        if (array_key_exists('output_path', $overrides) && is_string($overrides['output_path'])) {
            return $overrides['output_path'];
        }

        return $name === 'default'
            ? $this->rootOutputPath
            : rtrim($this->storagePath, '/') . sprintf('/openapi-%s.yaml', $name);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function resolveOptional(array $overrides, string $key, string $default): ?string
    {
        if (!array_key_exists($key, $overrides)) {
            return $default;
        }

        $value = $overrides[$key];

        if ($value === false || $value === null) {
            return null;
        }

        return (string) $value;
    }
}
