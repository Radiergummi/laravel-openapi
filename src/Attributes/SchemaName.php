<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;

/**
 * Overrides the component key a class maps to in `#/components/schemas/{key}`.
 *
 * The key is otherwise derived from the class basename by
 * {@see \Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry}. Use this to pin a name
 * against refactors or replace an ugly derived name. Two classes sharing the same name throw
 * {@see \Radiergummi\OpenApi\Support\Generator\DuplicateSchemaNameException}.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SchemaName
{
    public function __construct(
        public string $name,
    ) {}
}
