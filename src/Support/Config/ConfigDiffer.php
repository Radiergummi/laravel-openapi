<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Config;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_unique;
use function is_array;
use function sort;

/**
 * Compares the package's default config against a user-published copy, reporting added, removed,
 * and changed keys. Recurses into associative sub-arrays; list values are treated as leaves.
 *
 * @phpstan-type DiffEntry array{
 *     kind: 'added'|'removed'|'changed',
 *     path: string,
 *     default?: mixed,
 *     user?: mixed
 * }
 *
 * @internal
 */
final class ConfigDiffer
{
    /**
     * @param array<string, mixed> $default
     * @param array<string, mixed> $user
     *
     * @return list<array{kind: string, path: string, default?: mixed, user?: mixed}>
     */
    public static function diff(array $default, array $user, string $prefix = ''): array
    {
        /** @var list<array{kind: string, path: string, default?: mixed, user?: mixed}> $out */
        $out = [];

        $keys = array_unique([...array_keys($default), ...array_keys($user)]);
        sort($keys);

        foreach ($keys as $key) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            $hasDefault = array_key_exists($key, $default);
            $hasUser = array_key_exists($key, $user);

            if ($hasDefault && !$hasUser) {
                $out[] = ['kind' => 'added', 'path' => $path, 'default' => $default[$key]];

                continue;
            }

            if (!$hasDefault && $hasUser) {
                $out[] = ['kind' => 'removed', 'path' => $path, 'user' => $user[$key]];

                continue;
            }

            $d = $default[$key];
            $u = $user[$key];

            if (is_array($d) && is_array($u) && !array_is_list($d) && !array_is_list($u)) {
                $out = [...$out, ...self::diff($d, $u, $path)];

                continue;
            }

            if ($d !== $u) {
                $out[] = ['kind' => 'changed', 'path' => $path, 'default' => $d, 'user' => $u];
            }
        }

        return $out;
    }
}
