<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi;

use Illuminate\Support\Str;
use OpenApi\Annotations\Schema;
use OpenApi\Generator;

use function class_basename;
use function get_object_vars;
use function in_array;

/**
 * Whether a swagger-php annotation value is still the {@see Generator::UNDEFINED} sentinel.
 *
 * swagger-php leaves unset annotation fields as the magic string `Generator::UNDEFINED` rather
 * than `null`. Comparing that sentinel against a property whose declared type does not include
 * it makes PHPStan report `identical.alwaysFalse`; routing the check through this `mixed`-typed
 * predicate keeps the comparison out of static analysis' reach, so call sites stay clean.
 *
 * @internal
 */
function is_undefined(mixed $value): bool
{
    return Generator::isDefault($value);
}

/**
 * Whether a swagger-php annotation value is set; i.e., not the {@see Generator::UNDEFINED} sentinel
 *
 * The inverse of {@see is_undefined()}.
 *
 * @internal
 */
function is_defined(mixed $value): bool
{
    return !Generator::isDefault($value);
}

/**
 * Turns a class name into a human-readable resource label for consumer-facing prose.
 *
 * Strips the namespace and splits the StudlyCase basename into words, so
 * `App\Models\GroupMembership` becomes `Group Membership` and `App\Models\User` stays `User`.
 * Use this anywhere a class would otherwise surface verbatim in the generated spec
 * (descriptions, summaries) — the fully qualified name is an internal source detail that
 * must not leak to spec consumers.
 *
 * @internal
 */
function class_resource_name(string $class): string
{
    return Str::headline(class_basename($class));
}

/**
 * Copies the defined JSON-Schema fields of one {@see Schema} onto another.
 *
 * Since `OA\Property` and `OA\Items` both extend `OA\Schema`, this is how a resolved schema is
 * re-homed as a named property or an array item.
 * swagger-php internals (underscore-prefixed fields) and the component-key fields (`property`,
 * `schema`) are skipped, as are {@see is_undefined()} values.
 *
 * @template T of Schema
 *
 * @param T $target
 *
 * @return T
 *
 * @internal
 */
function copy_schema_fields(Schema $source, Schema $target): Schema
{
    foreach (get_object_vars($source) as $field => $value) {
        if (
            $field[0] === '_'
            || is_undefined($value)
            || in_array($field, ['property', 'schema'], strict: true)
        ) {
            continue;
        }

        $target->{$field} = $value;
    }

    return $target;
}
