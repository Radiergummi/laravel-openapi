<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Radiergummi\OpenApi\Enums\ComponentType;

use function preg_match;

/**
 * Sole owner of the OpenAPI component `$ref` format `#/components/{type}/{key}` — a JSON-Pointer
 * that is a serialization detail of the wire format. Every site that builds or parses such a ref
 * routes through here, so the literal lives in exactly one place.
 *
 * Pure and stateless: callable from lint rules, attributes, and plugins alike, without injecting
 * the stateful {@see ComponentSchemaRegistry}.
 *
 * @internal
 */
final class ComponentReference
{
    /**
     * Build a component `$ref` pointer for the given key and type.
     */
    public static function pointer(string $key, ComponentType $type = ComponentType::Schemas): string
    {
        return "#/components/{$type->value}/{$key}";
    }

    /**
     * Parse a schema `$ref` (`#/components/schemas/Foo`) to its name (`Foo`), or null when the ref
     * is not a local schema component reference.
     */
    public static function name(string $ref): ?string
    {
        $parsed = self::parse($ref);

        if ($parsed === null || $parsed['type'] !== ComponentType::Schemas->value) {
            return null;
        }

        return $parsed['name'];
    }

    /**
     * Parse any component `$ref` (`#/components/{type}/{name}`) into its type and name, or null when
     * the string is not a local component reference.
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
