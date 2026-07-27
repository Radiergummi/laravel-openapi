<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use OpenApi\Annotations as OA;
use OpenApi\Generator;

use function get_object_vars;
use function in_array;
use function is_array;
use function is_string;
use function Radiergummi\OpenApi\is_defined;
use function Radiergummi\OpenApi\is_schema_field;
use function Radiergummi\OpenApi\is_undefined;

/**
 * OAS 3.1-compatible nullable schema wrapping (OAS 3.1 removed `nullable`), and the single owner
 * of that rule: {@see wrap()} is {@see applyTo()} on a copy, so the two cannot drift apart.
 *
 * - Scalar type (`string`/`integer`/`number`/`boolean`), or a type array of nothing but scalars:
 *   widened to a type array including `'null'`.
 * - Anything else that carries constraints (structured type, mixed type array, `$ref`, composed
 *   schema): split into `oneOf: [<inner>, {type: 'null'}]`, with the constraint keywords moved to
 *   the inner schema and the documentation keywords left on the outer node. Splitting rather than
 *   widening also keeps `type: 'array'` a plain string, which swagger-php's `OA\Items` parent
 *   check requires.
 * - A schema carrying no constraints at all is left untouched: it already permits null, and a
 *   `oneOf` would in fact forbid it, since null matches both branches and exactly-one then fails.
 *
 * @internal
 */
final class NullableSchema
{
    /** Scalar OAS types that can be safely widened to a type array. */
    private const array SCALAR_TYPES = ['string', 'integer', 'number', 'boolean'];

    /**
     * Keywords that document the field as a whole rather than constrain its value. They stay on
     * the outer node when a schema is split: nested inside the `oneOf`, each would describe only
     * the non-null branch.
     */
    private const array DOCUMENTATION_KEYWORDS = [
        'default',
        'deprecated',
        'description',
        'example',
        'examples',
        'externalDocs',
        'nullable',
        'readOnly',
        'title',
        'writeOnly',
        'x',
        'xml',
    ];

    /**
     * swagger-php carriers for annotations attached to the schema. Not JSON-Schema keywords, and
     * not underscore-prefixed like the library's other internals, so they need naming explicitly.
     */
    private const array ANNOTATION_CARRIERS = ['attachables', 'encoding'];

    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * Returns a nullable copy of `$schema` using the OAS 3.1 idiom. Never mutates the input.
     */
    public static function wrap(OA\Schema $schema): OA\Schema
    {
        $copy = clone $schema;

        self::applyTo($copy);

        return $copy;
    }

    /**
     * Applies OAS 3.1 nullability to `$target` in place.
     *
     * Use when the schema is already held by reference in a collection and cannot be replaced.
     */
    public static function applyTo(OA\Schema $target): void
    {
        // Keywords alongside a $ref are ignored in OAS 3.1, so a ref is always split, never widened.
        if (is_undefined($target->ref) && self::widenType($target)) {
            return;
        }

        self::split($target);
    }

    /**
     * Widens a `type` consisting of nothing but scalars to include `'null'`, in place.
     *
     * Returns false when the type is structured, mixed, or absent, meaning the schema needs the
     * `oneOf` split instead.
     */
    private static function widenType(OA\Schema $target): bool
    {
        $type = $target->type;

        if (is_defined($type) && is_string($type)) {
            if (!in_array($type, self::SCALAR_TYPES, strict: true)) {
                return false;
            }

            $target->type = [$type, 'null'];

            return true;
        }

        if (!is_defined($type) || !is_array($type)) {
            return false;
        }

        // Already nullable, whatever the other members are.
        if (in_array('null', $type, strict: true)) {
            return true;
        }

        foreach ($type as $member) {
            if (!in_array($member, self::SCALAR_TYPES, strict: true)) {
                return false;
            }
        }

        $target->type = [...$type, 'null'];

        return true;
    }

    /**
     * Moves every constraint keyword off `$target` into a fresh inner schema, then replaces them
     * with `oneOf: [<inner>, {type: 'null'}]`.
     *
     * The keyword set is derived from the annotation object itself rather than enumerated, so a
     * keyword added upstream lands inside the wrapper without anyone maintaining a list.
     */
    private static function split(OA\Schema $target): void
    {
        $inner = new OA\Schema([]);
        $moved = false;

        foreach (get_object_vars($target) as $field => $value) {
            if (
                !is_schema_field($field, $value)
                || in_array($field, self::DOCUMENTATION_KEYWORDS, strict: true)
                || in_array($field, self::ANNOTATION_CARRIERS, strict: true)
            ) {
                continue;
            }

            $inner->{$field} = $value;
            $target->{$field} = Generator::UNDEFINED;
            $moved = true;
        }

        // An unconstrained schema already permits null; wrapping it would reject null instead.
        if (!$moved) {
            return;
        }

        $target->oneOf = [
            $inner,
            new OA\Schema(['type' => 'null']),
        ];
    }
}
