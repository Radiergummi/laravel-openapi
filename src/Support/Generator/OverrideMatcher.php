<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Illuminate\Support\Str;

use function in_array;
use function ltrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function usort;

/**
 * Resolves the merged, allowlist-filtered override field-set for a single operation, and detects
 * config keys that match nothing.
 *
 * Precedence (ascending — the most specific match wins per field):
 *   1. URI globs, ordered by specificity (count of literal, non-`*` characters); ties broken by
 *      declaration order, later key winning. `*` matches any run of characters, including `/`.
 *   2. The exact route-name key, applied last (highest precedence).
 *
 * A config key is an exact route-name match for an operation when it equals that operation's route
 * name; otherwise it is matched as a URI glob against the operation's URI.
 *
 * Pure utility — constructed with the raw `openapi.overrides` config array, no container dependency.
 *
 * @internal
 */
final class OverrideMatcher
{
    /**
     * Operation-level fields that may be set from config. Any `x-*` vendor extension is also
     * allowed (see {@see isAllowedField()}).
     *
     * @var list<string>
     */
    public const array ALLOWED_FIELDS = [
        'operationId',
        'summary',
        'description',
        'tags',
        'deprecated',
    ];

    /**
     * @param array<string, array<string, mixed>> $overrides
     */
    public function __construct(private readonly array $overrides) {}

    /**
     * Whether any override is configured at all. Lets callers skip work entirely on the default
     * install, where `openapi.overrides` is empty.
     */
    public bool $hasOverrides {
        get => $this->overrides !== [];
    }

    /**
     * The merged, allowlist-filtered field-set for one operation.
     *
     * @return array<string, mixed>
     */
    public function fieldsFor(?string $routeName, string $uri): array
    {
        $uri = $this->normalise($uri);

        /** @var list<array{specificity: int, order: int, fields: array<string, mixed>}> $globMatches */
        $globMatches = [];
        $nameFields = [];
        $order = 0;

        foreach ($this->overrides as $key => $block) {
            $fields = $this->filter($block);

            if ($fields === []) {
                $order++;

                continue;
            }

            if ($routeName !== null && $key === $routeName) {
                $nameFields = $fields;
            } elseif ($this->matchesGlob($key, $uri)) {
                $globMatches[] = [
                    'specificity' => $this->specificity($key),
                    'order' => $order,
                    'fields' => $fields,
                ];
            }

            $order++;
        }

        // Ascending precedence: least specific first, ties by declaration order (later last).
        usort($globMatches, static fn(array $a, array $b): int
            => $a['specificity'] <=> $b['specificity']
            ?: $a['order'] <=> $b['order']);

        $merged = [];

        foreach ($globMatches as $match) {
            $merged = [...$merged, ...$match['fields']];
        }

        // Exact route-name key wins last.
        return [...$merged, ...$nameFields];
    }

    private function normalise(string $uri): string
    {
        return ltrim($uri, '/');
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private function filter(array $block): array
    {
        $filtered = [];

        foreach ($block as $field => $value) {
            if (self::isAllowedField($field)) {
                $filtered[$field] = $value;
            }
        }

        return $filtered;
    }

    public static function isAllowedField(string $field): bool
    {
        return in_array($field, self::ALLOWED_FIELDS, strict: true)
            || str_starts_with($field, 'x-');
    }

    private function matchesGlob(string $pattern, string $normalisedUri): bool
    {
        // Str::is treats `*` as `.*`, matching any run of characters including `/`.
        return Str::is($this->normalise($pattern), $normalisedUri);
    }

    private function specificity(string $pattern): int
    {
        return strlen(str_replace('*', '', $this->normalise($pattern)));
    }

    /**
     * Config keys that matched no route name and no URI across the given set.
     *
     * @param list<array{name: ?string, uri: string}> $routes
     *
     * @return list<string>
     */
    public function unusedKeys(array $routes): array
    {
        $unused = [];

        foreach ($this->overrides as $key => $_block) {
            if (!$this->matchesAnyRoute($key, $routes)) {
                $unused[] = $key;
            }
        }

        return $unused;
    }

    /**
     * @param list<array{name: ?string, uri: string}> $routes
     */
    private function matchesAnyRoute(string $key, array $routes): bool
    {
        foreach ($routes as $route) {
            if ($route['name'] !== null && $route['name'] === $key) {
                return true;
            }

            if ($this->matchesGlob($key, $this->normalise($route['uri']))) {
                return true;
            }
        }

        return false;
    }
}
