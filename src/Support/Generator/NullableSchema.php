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
 * - An empty `type` array is degenerate input this rule can neither widen nor wrap, so it is
 *   returned exactly as supplied (`type: []`), for spec validation to report: widening appends to
 *   nothing and yields `type: ['null']`, which looks valid but admits only null, and wrapping
 *   renders as `oneOf: [{}, {type: 'null'}]`, which rejects null instead.
 * - A schema that already permits null (a `'null'` type, or a `oneOf`/`anyOf` branch typed `null`)
 *   is likewise left untouched, so applying the rule twice is the same as applying it once.
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
        // Applying the rule twice would nest the wrapper, and null then matches both outer
        // branches: `oneOf` demands exactly one, so the schema would accept nothing at all.
        if (self::isAlreadyNullable($target)) {
            return;
        }

        // Keywords alongside a $ref are ignored in OAS 3.1, so a ref is always split, never widened.
        if (is_undefined($target->ref) && self::widenType($target)) {
            return;
        }

        self::split($target);
    }

    /**
     * Reports whether the schema already permits null, in either form the rule produces.
     */
    private static function isAlreadyNullable(OA\Schema $target): bool
    {
        if (self::isNullType($target->type)) {
            return true;
        }

        foreach ([$target->oneOf, $target->anyOf] as $branches) {
            if (!is_defined($branches) || !is_array($branches)) {
                continue;
            }

            foreach ($branches as $branch) {
                if ($branch instanceof OA\Schema && self::isNullType($branch->type)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Reports whether a `type` member admits null, as either the bare type or a type array member.
     */
    private static function isNullType(mixed $type): bool
    {
        if (!is_defined($type)) {
            return false;
        }

        return $type === 'null' || (is_array($type) && in_array('null', $type, strict: true));
    }

    /**
     * Widens a `type` consisting of nothing but scalars to include `'null'`, in place.
     *
     * Returns false when the type is structured, mixed, empty, or absent, meaning the schema needs
     * the `oneOf` split instead.
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

        if (!is_defined($type) || !is_array($type) || $type === []) {
            return false;
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
     * Reports whether a field is a `type` carrying an empty array.
     *
     * That is degenerate input rather than an absent constraint: the array form means "one of these
     * types", an empty list offers none, and the meta-schema requires it non-empty. Neither
     * transformation salvages it, so it stays on the node exactly as supplied and spec validation
     * can report it. Widening leaves `type: ['null']`, admitting only null; moving it into the
     * wrapper makes it vanish (a bare inner schema serialises 3.0-style, which filters an empty type
     * array out), leaving `oneOf: [{}, {type: 'null'}]`, which rejects null instead.
     */
    private static function isEmptyTypeArray(string $field, mixed $value): bool
    {
        return $field === 'type' && $value === [];
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
                || self::isEmptyTypeArray($field, $value)
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
