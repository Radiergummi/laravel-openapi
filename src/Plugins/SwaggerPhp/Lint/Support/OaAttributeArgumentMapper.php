<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use OpenApi\Annotations as OA;

use function array_key_exists;
use function get_object_vars;
use function in_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function Radiergummi\OpenApi\is_defined;

/**
 * Maps a hand-authored `OA\Property` / `OA\Parameter(query)` to the scalar named arguments of the
 * equivalent field attribute, or `null` when the annotation carries a key the attribute cannot
 * express as a scalar argument.
 *
 * The mapper is the single source of truth for the Phase-1 replacement whitelist. It refuses (`null`)
 * the moment an annotation carries a non-scalar shape (`enum`, array `example`/`default`, `items`,
 * `x`, union/array `type`, nested objects) so a partial rewrite never silently drops a key; the rule
 * logs every such refusal. A `null` return therefore means "leave the annotation, do not flag".
 *
 * @internal
 */
final readonly class OaAttributeArgumentMapper
{
    /**
     * Schema keys whose value is a scalar the field attributes accept verbatim. `type` is handled
     * separately (it must be a single scalar OA type, never an array/union).
     */
    private const array SCALAR_SCHEMA_KEYS = [
        'title',
        'description',
        'format',
        'pattern',
        'example',
        'default',
        'nullable',
        'readOnly',
        'writeOnly',
        'uniqueItems',
        'deprecated',
        'minimum',
        'maximum',
        'exclusiveMinimum',
        'exclusiveMaximum',
        'multipleOf',
        'minLength',
        'maxLength',
        'minItems',
        'maxItems',
    ];

    /**
     * The scalar argument map for an `OA\Property`, or null when any carried key is unmappable.
     *
     * @return null|array<string, bool|float|int|string>
     */
    public function mapProperty(OA\Property $property): ?array
    {
        return $this->mapSchema($property, ['property']);
    }

    /**
     * The scalar argument map for a query `OA\Parameter` (`name` + `required` + the nested schema's
     * scalar keys), or null. A non-query parameter, or one carrying a non-scalar schema key, yields
     * null.
     *
     * @return null|array<string, bool|float|int|string>
     */
    public function mapQueryParameter(OA\Parameter $parameter): ?array
    {
        if (!is_defined($parameter->in) || $parameter->in !== 'query' || !is_defined($parameter->name)) {
            return null;
        }

        $arguments = ['name' => $parameter->name];

        if (is_defined($parameter->required) && $parameter->required === true) {
            $arguments['required'] = true;
        }

        if (is_defined($parameter->deprecated) && $parameter->deprecated === true) {
            $arguments['deprecated'] = true;
        }

        if (is_defined($parameter->description)) {
            if (!is_string($parameter->description)) {
                return null;
            }

            $arguments['description'] = $parameter->description;
        }

        $schema = $parameter->schema;

        if ($schema instanceof OA\Schema) {
            $schemaArguments = $this->mapSchema($schema, []);

            if ($schemaArguments === null) {
                return null;
            }

            $arguments = [...$arguments, ...$schemaArguments];
        } elseif (is_defined($schema)) {
            // A schema present but not a plain OA\Schema (e.g. a $ref) is out of scope.
            return null;
        }

        // `name` alone carries nothing worth replacing.
        return array_key_exists('description', $arguments)
            || array_key_exists('required', $arguments)
            || array_key_exists('deprecated', $arguments)
            || $this->hasSchemaArgument($arguments)
            ? $arguments
            : null;
    }

    /**
     * Scalar args from a schema-shaped annotation. `$ignoredKeys` are component-key fields (e.g.
     * `property`) that carry the member name, not schema content. Returns null on any defined key
     * outside the scalar whitelist, or when nothing mappable is carried.
     *
     * @param list<string> $ignoredKeys
     *
     * @return null|array<string, bool|float|int|string>
     */
    private function mapSchema(OA\Schema $schema, array $ignoredKeys): ?array
    {
        $arguments = [];

        foreach (get_object_vars($schema) as $key => $value) {
            if ($key[0] === '_' || !is_defined($value) || in_array($key, $ignoredKeys, strict: true)) {
                continue;
            }

            if ($key === 'type') {
                if (!is_string($value)) {
                    return null;
                }

                $arguments['type'] = $value;

                continue;
            }

            if (!in_array($key, self::SCALAR_SCHEMA_KEYS, strict: true) || !$this->isScalar($value)) {
                return null;
            }

            $arguments[$key] = $value;
        }

        return $arguments === [] ? null : $arguments;
    }

    /**
     * @param array<string, bool|float|int|string> $arguments
     */
    private function hasSchemaArgument(array $arguments): bool
    {
        foreach ($arguments as $key => $_) {
            if ($key !== 'name' && $key !== 'required' && $key !== 'deprecated') {
                return true;
            }
        }

        return false;
    }

    private function isScalar(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value);
    }
}
