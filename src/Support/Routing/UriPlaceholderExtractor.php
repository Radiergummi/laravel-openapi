<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

use function explode;
use function ltrim;
use function preg_match_all;
use function str_contains;

/**
 * Extracts URI template placeholders from a route URI, shared by the generator and the linter.
 *
 * @internal
 */
final class UriPlaceholderExtractor
{
    /**
     * Extracts every URI placeholder from the route template as `[bareName, optional]`.
     *
     * The bare name strips both the binding-prefix characters Laravel allows (e.g. `{+slug}`) and a
     * `{param:field}` custom-key suffix: the URI template variable is `param`, and Laravel keys its
     * `wheres`/binding metadata on that bare name, so emitting or looking up `param:field` would be
     * wrong on both counts.
     *
     * @return list<array{string, bool}>
     */
    public static function extract(string $uri): array
    {
        if (preg_match_all('/\{([^?}]+)(\??)}/', $uri, $matches) === 0) {
            return [];
        }

        $placeholders = [];

        foreach ($matches[1] as $index => $raw) {
            $name = ltrim($raw, '+#./;?&=,!@|');
            $bareName = str_contains($name, ':')
                ? explode(':', $name, 2)[0]
                : $name;

            $placeholders[] = [$bareName, $matches[2][$index] === '?'];
        }

        return $placeholders;
    }
}
