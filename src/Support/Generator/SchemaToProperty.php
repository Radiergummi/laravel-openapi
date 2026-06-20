<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use OpenApi\Annotations as OA;

use function Radiergummi\OpenApi\is_defined;

/**
 * Copies the JSON-Schema/OAS fields of an {@see OA\Schema} into a named {@see OA\Property}.
 *
 * Only the documented schema keywords are carried over (allowlist); swagger-php internals
 * (`_context`, `_unmerged`, …) and any field left at `Generator::UNDEFINED` are skipped. Shared by
 * every path that derives a property from a type-resolved schema (Spatie Data classes, API Resource
 * value objects).
 *
 * @internal
 */
final class SchemaToProperty
{
    /** @var list<string> */
    private const array ALLOWLIST = [
        'type',
        'format',
        'ref',
        'oneOf',
        'allOf',
        'anyOf',
        'items',
        'enum',
        'description',
        'example',
        'nullable',
        'minimum',
        'maximum',
        'exclusiveMinimum',
        'exclusiveMaximum',
        'minLength',
        'maxLength',
        'pattern',
        'minItems',
        'maxItems',
        'uniqueItems',
        'multipleOf',
        'properties',
        'additionalProperties',
        'required',
        'deprecated',
        'readOnly',
        'writeOnly',
        'default',
        'title',
    ];

    public static function convert(string $name, OA\Schema $schema): OA\Property
    {
        $props = ['property' => $name];

        foreach (self::ALLOWLIST as $field) {
            $value = $schema->{$field};

            if (is_defined($value)) {
                $props[$field] = $value;
            }
        }

        return new OA\Property($props);
    }
}
