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
 * swagger-php leaves unset fields as `Generator::UNDEFINED` instead of `null`. Routing the
 * check through this `mixed`-typed predicate keeps it out of PHPStan's reach (`identical.alwaysFalse`).
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
 * Turns a class name into a human-readable label: strips the namespace and splits StudlyCase
 * into words (`App\Models\GroupMembership` → `Group Membership`). Use in descriptions and
 * summaries to avoid leaking fully-qualified names into the spec.
 *
 * @internal
 */
function class_resource_name(string $class): string
{
    return Str::headline(class_basename($class));
}

/**
 * Copies defined JSON-Schema fields from one {@see Schema} onto another, skipping swagger-php
 * internals (underscore-prefixed), component-key fields (`property`, `schema`), and undefined values.
 * Used to re-home a resolved schema as a named property or array item.
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
