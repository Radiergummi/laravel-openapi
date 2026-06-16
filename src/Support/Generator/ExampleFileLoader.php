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
 * Loads and caches `file:`-based example payloads for a single generation run.
 *
 * @internal
 */
#[Scoped]
final class ExampleFileLoader
{
    /** @var array<string, mixed> */
    private array $cache = [];

    /**
     * JSON-decodes a file relative to the project root. Results are cached.
     *
     * @throws RuntimeException When the file cannot be read or is not valid JSON.
     */
    public function load(string $relativePath): mixed
    {
        if (isset($this->cache[$relativePath])) {
            return $this->cache[$relativePath];
        }

        $absolute = base_path($relativePath);

        // Suppress the PHP warning; the false return is converted to a consistent RuntimeException.
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
