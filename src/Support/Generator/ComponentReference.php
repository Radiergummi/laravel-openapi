<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Radiergummi\OpenApi\Enums\ComponentType;

use function preg_match;

/**
 * Single owner of the `#/components/{type}/{key}` ref format. Pure and stateless.
 *
 * @internal
 */
final class ComponentReference
{
    /** Build a `$ref` pointer for the given key and component type. */
    public static function pointer(string $key, ComponentType $type = ComponentType::Schemas): string
    {
        return "#/components/{$type->value}/{$key}";
    }

    /** Parse a schema `$ref` to its name, or null when it is not a local schemas component ref. */
    public static function name(string $ref): ?string
    {
        $parsed = self::parse($ref);

        if ($parsed === null || $parsed['type'] !== ComponentType::Schemas->value) {
            return null;
        }

        return $parsed['name'];
    }

    /**
     * Parse a component `$ref` into its type and name, or null when not a local component ref.
     *
     * @return null|array{type: string, name: string}
     */
    public static function parse(string $ref): ?array
    {
        if (preg_match('~^#/components/([^/]+)/(.+)$~', $ref, $matches) !== 1) {
            return null;
        }

        return ['type' => $matches[1], 'name' => $matches[2]];
    }
}
