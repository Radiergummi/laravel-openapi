<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use OpenApi\Annotations as OA;

/**
 * Builds the `OA\Property` for a class-typed field: a `$ref` when the class resolved to a schema,
 * otherwise `type: object`. Inline example/enum directives are dropped (incompatible with `$ref`).
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
