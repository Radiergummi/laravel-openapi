<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use OpenApi\Annotations as OA;

/**
 * Builds the `OA\Property` for a field whose type is a class-string: a `$ref` when the class
 * resolved to a schema, else a permissive `type: object`. The field's (already cleaned)
 * description is propagated; inline example/enum directives are intentionally dropped — they do
 * not apply to a `$ref` schema. Shared by the ApiResources and Fractal schema builders, which
 * derive this property identically.
 *
 * @internal
 */
final class FieldReferenceProperty
{
    public static function build(string $propertyName, ?string $description, ?string $ref): OA\Property
    {
        $property = $ref !== null
            ? new OA\Property(['property' => $propertyName, 'ref' => $ref])
            : new OA\Property(['property' => $propertyName, 'type' => 'object']);

        if ($description !== null) {
            $property->description = $description;
        }

        return $property;
    }
}
