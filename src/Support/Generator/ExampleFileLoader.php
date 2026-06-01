<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Illuminate\Container\Attributes\Scoped;
use JsonException;
use RuntimeException;

use function base_path;
use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Resolves `file:`-based example payloads at spec-generation time.
 *
 * Loaded files are cached for the duration of a single generation run. The class is bound as
 * `scoped` in {@see OpenApiServiceProvider}, so each scope (each request under Octane) receives
 * a fresh instance.
 */
#[Scoped]
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
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "OpenAPI example file contains invalid JSON ({$absolute}): {$exception->getMessage()}",
                previous: $exception,
            );
        }

        $this->cache[$relativePath] = $decoded;

        return $decoded;
    }
}
