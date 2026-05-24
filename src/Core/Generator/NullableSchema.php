<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Generator;

use OpenApi\Annotations as OA;
use OpenApi\Generator;

use function in_array;
use function is_array;
use function is_string;

/**
 * OAS 3.1-compatible nullable schema wrapping.
 *
 * OAS 3.1 removed the `nullable` keyword. The correct representations are:
 *
 * - **Scalar schema** (has a concrete scalar `type` — `string`, `integer`, `number`, `boolean`):
 *   make `type` an array that includes `'null'`, e.g. `type: ['string', 'null']`.
 * - **Structured schema** (type is `array` or `object`, or the schema carries `items`/`properties`):
 *   wrap in `oneOf: [<inner>, {type: 'null'}]` so that swagger-php's validation of `OA\Items`
 *   (which requires `type === 'array'` as an exact string) continues to pass on the inner schema.
 * - **`$ref` schema** (bare reference, where extra keywords are ignored): wrap in
 *   `oneOf: [{$ref: …}, {type: 'null'}]`.
 * - **Other** (oneOf/allOf/enum without an explicit type): wrap in `oneOf` as well.
 */
final class NullableSchema
{
    /** Scalar OAS types that can be safely widened to a type array. */
    private const array SCALAR_TYPES = ['string', 'integer', 'number', 'boolean'];

    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * Returns a nullable version of `$schema` using the OAS 3.1 idiom.
     *
     * - Scalar `type` (string/integer/number/boolean): widened to `['type', 'null']`.
     * - Structured `type` (array/object) or schema with `items`/`properties`: wrapped in
     *   `oneOf: [<inner>, {type: 'null'}]` to preserve the `type: 'array'` string that
     *   swagger-php's `OA\Items` parent check requires.
     * - `$ref` schema: wrapped in `oneOf` because extra keywords alongside `$ref` are ignored.
     * - All other cases (no type, already a composition): wrapped in `oneOf`.
     *
     * The input schema is never mutated — a new {@see OA\Schema} is always returned.
     */
    public static function wrap(OA\Schema $schema): OA\Schema
    {
        $undefined = Generator::UNDEFINED;

        // $ref branch: extra fields alongside $ref are ignored by validators in OAS 3.1.
        if ($schema->ref !== $undefined && is_string($schema->ref)) {
            return new OA\Schema([
                'oneOf' => [
                    new OA\Schema(['ref' => $schema->ref]),
                    new OA\Schema(['type' => 'null']),
                ],
            ]);
        }

        // Scalar plain-type branch: widen to a type array that includes 'null'.
        if (
            $schema->type !== $undefined
            && is_string($schema->type)
            && in_array($schema->type, self::SCALAR_TYPES, strict: true)
        ) {
            $clone = clone $schema;
            $clone->type = [$schema->type, 'null'];

            return $clone;
        }

        // Already a type array (caller passed a pre-widened scalar schema): add 'null' if absent.
        // Only safe when all existing types are scalars (no 'array' or 'object' mixed in).
        if ($schema->type !== $undefined && is_array($schema->type)) {
            $hasStructured = false;

            foreach ($schema->type as $t) {
                if (!in_array($t, self::SCALAR_TYPES, strict: true) && $t !== 'null') {
                    $hasStructured = true;

                    break;
                }
            }

            if (!$hasStructured) {
                $clone = clone $schema;

                if (!in_array('null', $schema->type, strict: true)) {
                    $clone->type = [...$schema->type, 'null'];
                }

                return $clone;
            }
        }

        // Structured schema (array/object type, or carries items/properties): wrap in oneOf so
        // the inner schema keeps type: 'array' as an exact string, satisfying swagger-php's
        // OA\Items parent-type check.
        return new OA\Schema([
            'oneOf' => [
                $schema,
                new OA\Schema(['type' => 'null']),
            ],
        ]);
    }

    /**
     * Applies OAS 3.1 nullability to `$target` **in place**.
     *
     * Use this when the schema object is already referenced in a collection (e.g. a properties
     * list) and cannot be replaced with the new object that {@see wrap()} would return.
     *
     * The same branching logic as {@see wrap()} applies:
     * - Scalar type → widened to `['type', 'null']`.
     * - Already a type array of scalars → `'null'` appended if absent.
     * - Structured type (array/object) with `items` → type+items moved into a `oneOf` inner schema.
     * - No type / already composed → `type` set to `['null']` (fallback; callers should avoid this).
     */
    public static function applyTo(OA\Schema $target): void
    {
        $undefined = Generator::UNDEFINED;

        if (is_string($target->type) && $target->type !== $undefined) {
            if (in_array($target->type, ['array', 'object'], strict: true)) {
                $inner = new OA\Schema(['type' => $target->type]);

                if (!Generator::isDefault($target->items)) {
                    $inner->items = $target->items;
                    $target->items = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                }

                $target->type = Generator::UNDEFINED;
                $target->oneOf = [
                    $inner,
                    new OA\Schema(['type' => 'null']),
                ];
            } else {
                // Scalar type: widen to a type array.
                $target->type = [$target->type, 'null'];
            }
        } elseif (is_array($target->type) && !in_array('null', $target->type, strict: true)) {
            $target->type = [...$target->type, 'null'];
        } elseif ($target->type === $undefined) {
            $target->type = ['null'];
        }
    }
}
