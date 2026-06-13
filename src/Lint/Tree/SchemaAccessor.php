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

    /**
     * Extract the component name from a Response Reference Object
     * (`$ref: '#/components/responses/{name}'`), or null when the response is not a ref.
     */
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

        // OAS 3.1 allows `type` to be an array (e.g. ["string", "null"]). Collapse it to the first
        // concrete (non-"null") type so downstream rules can still reason about the field.
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
     * Classify a schema's `oneOf` / `anyOf` composition.
     *
     * Distinguishes the standard OAS 3.1 nullable encoding (one concrete branch plus one or more
     * pure `{type: 'null'}` branches — see {@see NullableSchema}) from a genuine union of multiple
     * alternatives. The nullable shape is unwrappable to its single non-null branch so field rules
     * can still inspect it; a genuine multi-branch union is not, and is reported instead.
     *
     * `branch` is the single non-null branch to inspect when the schema is the nullable shape (or a
     * defensive single-branch composition); null otherwise. `uninspectedComposite` is true when two
     * or more genuine (non-null) alternatives are present and therefore left uninspected.
     *
     * @return array{branch: null|OA\Schema, uninspectedComposite: bool}
     */
    public static function classifyComposition(OA\Schema $schema): array
    {
        $branches = self::compositionBranches($schema);

        if ($branches === []) {
            return ['branch' => null, 'uninspectedComposite' => false];
        }

        $nonNullBranches = array_values(array_filter(
            $branches,
            static fn(OA\Schema $branch): bool => !self::isPureNullSchema($branch),
        ));

        // Exactly one concrete branch (with or without accompanying null branches) is the nullable
        // shape — unwrap it so its fields get inspected like any other schema.
        if (count($nonNullBranches) === 1) {
            return ['branch' => $nonNullBranches[0], 'uninspectedComposite' => false];
        }

        // Two or more genuine alternatives: not the nullable shape. We do not union their fields.
        return [
            'branch' => null,
            'uninspectedComposite' => count($nonNullBranches) >= 2,
        ];
    }

    /**
     * Return the `oneOf` or `anyOf` branches of a schema as a list of concrete `OA\Schema` objects,
     * or an empty list when the schema declares neither. `oneOf` is preferred when both exist.
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

    /**
     * A branch is "pure null" when its only type is `null` — i.e. `type: 'null'` or
     * `type: ['null']` — with no `$ref`, properties, or further composition. This is the null
     * member of the OAS 3.1 nullable encoding.
     */
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

    public static function isNullable(OA\Schema $schema): bool
    {
        // OAS 3.0 style
        if ($schema->nullable === true && is_defined($schema->nullable)) {
            return true;
        }

        // OAS 3.1 style (type as array including "null")
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
