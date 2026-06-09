<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;

/**
 * Overrides the component key a class maps to in `#/components/schemas/{key}`.
 *
 * By default the key is derived from the class basename (disambiguated with namespace segments,
 * or a hash as a last resort) by {@see \Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry}.
 * That key is a public, consumer-facing contract — it becomes the type name in generated clients —
 * yet it tracks the PHP class name and location, so a rename or move silently changes it.
 *
 * Use this attribute as a scoped escape hatch: pin a schema name against refactors, or replace an
 * ugly derived name. It is an override, not a thing to put on every class — naming a class the same
 * as its basename only restates what derivation already produces.
 *
 * Two distinct classes declaring the same name is a conflict: generation throws
 * {@see \Radiergummi\OpenApi\Support\Generator\DuplicateSchemaNameException}.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SchemaName
{
    public function __construct(
        public string $name,
    ) {}
}
