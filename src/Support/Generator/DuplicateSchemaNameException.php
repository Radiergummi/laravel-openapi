<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use LogicException;

use function sprintf;

/**
 * Thrown when two distinct classes claim the same component schema name.
 *
 * A schema name — whether derived or pinned with {@see \Radiergummi\OpenApi\Attributes\SchemaName} —
 * is a public, consumer-facing identifier and must be unique. Two classes asking for the same name
 * is an unresolvable conflict the author must fix (rename or remove a `#[SchemaName]`), not a
 * recoverable condition — so it is an unchecked {@see LogicException} (see
 * `exceptions.uncheckedExceptionClasses` in `phpstan.neon`).
 *
 * @internal
 */
final class DuplicateSchemaNameException extends LogicException
{
    public static function between(string $name, string $existingOwner, string $claimingClass): self
    {
        return new self(sprintf(
            'Component schema name "%s" requested by %s is already claimed by %s. '
            . 'Schema names must be unique — rename or remove a #[SchemaName].',
            $name,
            $claimingClass,
            $existingOwner,
        ));
    }
}
