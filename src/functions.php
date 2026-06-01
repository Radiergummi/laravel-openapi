<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi;

use OpenApi\Generator;

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
 * Whether a swagger-php annotation value is set — i.e. not the {@see Generator::UNDEFINED} sentinel.
 *
 * The inverse of {@see is_undefined()}.
 *
 * @internal
 */
function is_defined(mixed $value): bool
{
    return !Generator::isDefault($value);
}
