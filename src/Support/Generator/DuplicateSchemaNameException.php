<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use LogicException;

use function sprintf;

/**
 * Thrown when two distinct classes claim the same component schema name.
 *
 * Schema names are unique consumer-facing identifiers; a collision is unresolvable without
 * renaming or removing a `#[SchemaName]` attribute.
 *
 * @internal
 */
final class DuplicateSchemaNameException extends LogicException
{
    public static function between(string $name, string $existingOwner, string $claimingClass): self
    {
        return new self(
            sprintf(
                'Component schema name "%s" requested by %s is already claimed by %s. '
                . 'Schema names must be unique — rename or remove a #[SchemaName].',
                $name,
                $claimingClass,
                $existingOwner,
            ),
        );
    }
}
