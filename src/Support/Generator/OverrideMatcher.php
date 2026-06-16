<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Illuminate\Support\Str;
use Radiergummi\OpenApi\Attributes\Webhook as WebhookAttribute;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

use function in_array;
use function ltrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function usort;

/**
 * Resolves the merged, allowlist-filtered override field-set for a single operation.
 *
 * Precedence (ascending): URI globs by specificity (literal character count), declaration order
 * breaking ties, then exact route-name key wins last. `*` matches any run of characters including `/`.
 *
 * @internal
 */
final class OverrideMatcher
{
    /**
     * Operation-level fields settable from config. Any `x-*` vendor extension is also allowed.
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
     * True when any override is configured; lets callers skip work on the default install.
     */
    public bool $hasOverrides {
        get => $this->overrides !== [];
    }

    /**
     * @param array<string, array<string, mixed>> $overrides
     */
    public function __construct(private readonly array $overrides) {}

    /**
     * The override lookup key for a webhook descriptor: the logical name from `#[Webhook]`.
     * Shared by {@see Stages\PathsStage} and the lint rule so webhook key semantics cannot drift.
     */
    public static function webhookKeyFor(ActionDescriptor $descriptor): ?string
    {
        $attribute = $descriptor->actionAttributes(WebhookAttribute::class)[0] ?? null;

        return $attribute?->newInstance()->name;
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
        return array_filter(
            $block,
            static fn(string $field): bool => self::isAllowedField($field),
            ARRAY_FILTER_USE_KEY,
        );
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
     * Config keys that matched no route: path routes match by name or URI; webhook routes by name
     * or webhook name (never URI), mirroring {@see Stages\OverridesStage}.
     *
     * @param list<array{name: ?string, uri: string, webhook?: ?string}> $routes
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
     * @param list<array{name: ?string, uri: string, webhook?: ?string}> $routes
     */
    private function matchesAnyRoute(string $key, array $routes): bool
    {
        foreach ($routes as $route) {
            if ($route['name'] !== null && $route['name'] === $key) {
                return true;
            }

            // Webhook operations are keyed by their webhook name, not their URI; match that
            // string the same way OverridesStage does. Path operations match on their URI.
            $globTarget = $route['webhook'] ?? $route['uri'];

            if ($this->matchesGlob($key, $this->normalise($globTarget))) {
                return true;
            }
        }

        return false;
    }
}
