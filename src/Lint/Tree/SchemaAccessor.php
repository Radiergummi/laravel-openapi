<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Tree;

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Enums\ComponentType;
use Radiergummi\OpenApi\Support\Generator\ComponentReference;

use function array_filter;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function Radiergummi\OpenApi\is_defined;
use function Radiergummi\OpenApi\is_undefined;

/**
 * Stateless accessors that translate swagger-php's `Generator::UNDEFINED` sentinels into
 * plain nullable values. Used heavily by {@see SpecTreeBuilder} when projecting the OA
 * annotation graph into the lint domain tree.
 */
final class SchemaAccessor
{
    /** Returns the component name from a `$ref: '#/components/responses/{name}'`, or null. */
    public static function extractResponseRef(OA\Response $response): ?string
    {
        $ref = $response->ref;

        if (!is_string($ref) || is_undefined($ref)) {
            return null;
        }

        $parsed = ComponentReference::parse($ref);

        return $parsed !== null && $parsed['type'] === ComponentType::Responses->value
            ? $parsed['name']
            : null;
    }

    public static function extractSchemaType(mixed $schema): ?string
    {
        if ($schema === null || is_undefined($schema)) {
            return null;
        }

        if (!$schema instanceof OA\Schema) {
            return null;
        }

        $type = $schema->type;

        if ($type === null || is_undefined($type)) {
            return null;
        }

        // OAS 3.1: `type` may be an array; return the first non-"null" entry.
        if (is_array($type)) {
            return array_find(
                $type,
                static fn(string $candidate): bool => $candidate !== 'null',
            );
        }

        return is_string($type) ? $type : null;
    }

    public static function extractSchemaPattern(mixed $schema): ?string
    {
        if ($schema === null || is_undefined($schema)) {
            return null;
        }

        if (!$schema instanceof OA\Schema) {
            return null;
        }

        // @phpstan-ignore nullCoalesce.property (defensive; pattern may be unset at runtime)
        $pattern = $schema->pattern ?? Generator::UNDEFINED;

        if (is_undefined($pattern)) {
            return null;
        }

        return is_string($pattern) ? $pattern : null;
    }

    /**
     * @return null|list<mixed>
     */
    public static function extractSchemaEnum(mixed $schema): ?array
    {
        if ($schema === null || is_undefined($schema)) {
            return null;
        }

        if (!$schema instanceof OA\Schema) {
            return null;
        }

        $enum = $schema->enum;

        if (!is_array($enum) || is_undefined($enum)) {
            return null;
        }

        return array_values($enum);
    }

    /**
     * Separates the OAS 3.1 nullable encoding (one concrete branch + pure null branches) from a
     * genuine multi-alternative union. `branch` is the single non-null branch, null otherwise.
     *
     * @return array{branch: null|OA\Schema, uninspectedComposite: bool}
     */
    public static function classifyComposition(OA\Schema $schema): array
    {
        $branches = self::compositionBranches($schema);

        if ($branches === []) {
            return ['branch' => null, 'uninspectedComposite' => false];
        }

        $nonNullBranches = array_values(
            array_filter(
                $branches,
                static fn(OA\Schema $branch): bool => !self::isPureNullSchema($branch),
            ),
        );

        if (count($nonNullBranches) === 1) {
            return ['branch' => $nonNullBranches[0], 'uninspectedComposite' => false];
        }

        return [
            'branch' => null,
            'uninspectedComposite' => count($nonNullBranches) >= 2,
        ];
    }

    /**
     * Returns `oneOf` or `anyOf` branches as concrete schema objects. `oneOf` wins when both exist.
     *
     * @return list<OA\Schema>
     */
    private static function compositionBranches(OA\Schema $schema): array
    {
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $value = $schema->{$keyword};

            if (!is_array($value) || is_undefined($value)) {
                continue;
            }

            $branches = [];

            foreach ($value as $branch) {
                if ($branch instanceof OA\Schema && is_defined($branch)) {
                    $branches[] = $branch;
                }
            }

            if ($branches !== []) {
                return $branches;
            }
        }

        return [];
    }

    /** Returns true for `{type: 'null'}` branches (the OAS 3.1 nullable null member). */
    private static function isPureNullSchema(OA\Schema $schema): bool
    {
        if (self::extractRef($schema) !== null) {
            return false;
        }

        $type = $schema->type;

        if ($type === 'null') {
            return true;
        }

        return is_array($type) && !is_undefined($type) && $type === ['null'];
    }

    /**
     * @param null|array<string, mixed>|OA\Schema|string $schema
     */
    public static function extractRef(OA\Schema|array|string|null $schema): ?string
    {
        if ($schema === null || is_undefined($schema)) {
            return null;
        }

        $ref = $schema->ref ?? Generator::UNDEFINED;

        if (!is_string($ref) || is_undefined($ref)) {
            return null;
        }

        return ComponentReference::name($ref);
    }

    /** Returns the first inline media-type schema; skips `$ref` schemas (inspected at their definition). */
    public static function bodySchema(OA\Response|OA\RequestBody $body): ?OA\Schema
    {
        $content = $body->content;

        if (!is_array($content) || is_undefined($content)) {
            return null;
        }

        foreach ($content as $mediaType) {
            if (!$mediaType instanceof OA\MediaType || is_undefined($mediaType)) {
                continue;
            }

            $schema = $mediaType->schema;

            if (
                !$schema instanceof OA\Schema
                || is_undefined($schema)
                || self::extractRef($schema) !== null
            ) {
                continue;
            }

            return $schema;
        }

        return null;
    }

    public static function isNullable(OA\Schema $schema): bool
    {
        // OAS 3.0: nullable flag
        if ($schema->nullable === true && is_defined($schema->nullable)) {
            return true;
        }

        // OAS 3.1: type array containing "null"
        $type = $schema->type;

        return is_array($type) && in_array('null', $type, true);
    }

    public static function undefinedToNull(mixed $value): ?string
    {
        if ($value === null || is_undefined($value)) {
            return null;
        }

        return is_string($value) ? $value : null;
    }
}
