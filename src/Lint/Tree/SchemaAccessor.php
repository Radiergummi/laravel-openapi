<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Tree;

use OpenApi\Annotations as OA;
use OpenApi\Generator;

use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;

/**
 * Stateless accessors that translate swagger-php's `Generator::UNDEFINED` sentinels into
 * plain nullable values. Used heavily by {@see SpecTreeBuilder} when projecting the OA
 * annotation graph into the lint domain tree.
 */
final class SchemaAccessor
{
    /**
     * @param null|array<string, mixed>|OA\Schema|string $schema
     */
    public static function extractRef(OA\Schema|array|string|null $schema): ?string
    {
        if ($schema === null || Generator::isDefault($schema)) {
            return null;
        }

        $ref = $schema->ref ?? Generator::UNDEFINED;

        if (
            Generator::isDefault($ref)
            || $ref === null // @phpstan-ignore identical.alwaysFalse (defensive; swagger-php may emit null at runtime)
            || !is_string($ref)
        ) {
            return null;
        }

        if (preg_match('~^#/components/schemas/(.+)$~', $ref, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public static function extractSchemaType(mixed $schema): ?string
    {
        if ($schema === null || Generator::isDefault($schema)) {
            return null;
        }

        if (!$schema instanceof OA\Schema) {
            return null;
        }

        $type = $schema->type;

        if (Generator::isDefault($type) || $type === null) {
            return null;
        }

        // OAS 3.1 allows `type` to be an array (e.g. ["string", "null"]).
        // Collapse it to the first concrete (non-"null") type so downstream
        // rules can still reason about the field.
        if (is_array($type)) {
            foreach ($type as $candidate) {
                if (is_string($candidate) && $candidate !== 'null') {
                    return $candidate;
                }
            }

            return null;
        }

        return is_string($type) ? $type : null;
    }

    public static function extractSchemaPattern(mixed $schema): ?string
    {
        if ($schema === null || Generator::isDefault($schema)) {
            return null;
        }

        if (!$schema instanceof OA\Schema) {
            return null;
        }

        // @phpstan-ignore nullCoalesce.property (defensive; pattern may be unset at runtime)
        $pattern = $schema->pattern ?? Generator::UNDEFINED;

        if (
            Generator::isDefault($pattern)
            || $pattern === null // @phpstan-ignore identical.alwaysFalse (defensive; pattern may be null at runtime)
        ) {
            return null;
        }

        return is_string($pattern) ? $pattern : null;
    }

    /**
     * @return null|list<mixed>
     */
    public static function extractSchemaEnum(mixed $schema): ?array
    {
        if ($schema === null || Generator::isDefault($schema)) {
            return null;
        }

        if (!$schema instanceof OA\Schema) {
            return null;
        }

        $enum = $schema->enum;

        if (Generator::isDefault($enum) || !is_array($enum)) {
            return null;
        }

        return array_values($enum);
    }

    public static function isNullable(OA\Schema $schema): bool
    {
        // OAS 3.0 style
        if (
            !Generator::isDefault($schema->nullable)
            && $schema->nullable === true
        ) {
            return true;
        }

        // OAS 3.1 style (type as array including "null")
        $type = $schema->type;

        return is_array($type) && in_array('null', $type, true);
    }

    public static function undefinedToNull(mixed $value): ?string
    {
        if (Generator::isDefault($value) || $value === null) {
            return null;
        }

        return is_string($value) ? $value : null;
    }
}
