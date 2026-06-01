<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Illuminate\Container\Attributes\Scoped;

use function array_key_exists;
use function ltrim;
use function strtoupper;

/**
 * Per-run map of `(HTTP method, URI)` → route name.
 *
 * {@see Stages\PathsStage} records an entry as it builds
 * each operation (it already holds the route name and URI); {@see Stages\OverridesStage} reads it
 * back to resolve the route name for an assembled operation without re-walking routes. Mirrors
 * {@see ComponentSchemaRegistry}: mutable per-run state, reset between runs by the scoped lifecycle.
 *
 * Keys are normalised as `"{UPPER_METHOD} /{uri-without-leading-slash}"`, matching the convention
 * in {@see \Radiergummi\OpenApi\Lint\Tree\SpecTreeBuilder}, so callers may pass either a raw
 * `route->uri()` or a leading-slash `$pathItem->path` and get a consistent key.
 *
 * @internal
 */
#[Scoped]
final class RouteIndex
{
    /**
     * @var array<string, ?string> keyed by "{METHOD} /{uri}" → route name (null for unnamed routes)
     */
    private array $names = [];

    public function record(string $uri, string $method, ?string $routeName): void
    {
        $this->names[$this->key($uri, $method)] = $routeName;
    }

    public function routeNameFor(string $uri, string $method): ?string
    {
        return $this->names[$this->key($uri, $method)] ?? null;
    }

    public function has(string $uri, string $method): bool
    {
        return array_key_exists($this->key($uri, $method), $this->names);
    }

    private function key(string $uri, string $method): string
    {
        return strtoupper($method) . ' /' . ltrim($uri, '/');
    }
}
