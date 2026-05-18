<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Generator;

use JsonException;
use RuntimeException;

use function base_path;
use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Resolves `file:`-based example payloads at spec-generation time.
 *
 * Loaded files are cached for the duration of a single generation run. Call {@see reset()} at the
 * start of each run (Octane safety).
 */
final class ExampleFileLoader
{
    /** @var array<string, mixed> */
    private array $cache = [];

    /**
     * Loads and JSON-decodes a file relative to the project root. Results are cached — later calls
     * for the same path are free.
     *
     * @throws RuntimeException When the file cannot be read or is not valid JSON.
     */
    public function load(string $relativePath): mixed
    {
        if (isset($this->cache[$relativePath])) {
            return $this->cache[$relativePath];
        }

        $absolute = base_path($relativePath);

        // Suppress the PHP warning so callers receive a consistent RuntimeException regardless of
        // whether the file is missing or unreadable.
        $contents = @file_get_contents($absolute);

        if ($contents === false) {
            throw new RuntimeException(
                "OpenAPI example file not found or unreadable: {$absolute}",
            );
        }

        try {
            $decoded = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                "OpenAPI example file contains invalid JSON ({$absolute}): {$e->getMessage()}",
                previous: $e,
            );
        }

        $this->cache[$relativePath] = $decoded;

        return $decoded;
    }

    /**
     * Resets the per-run file cache.
     *
     * Under Octane the container is long-lived, so `scoped` bindings are re-created per request
     * rather than per process. This method exists so that callers (and tests) can explicitly
     * flush accumulated state when a fresh generation run starts, independent of the binding
     * lifecycle.
     */
    public function reset(): void
    {
        $this->cache = [];
    }
}
