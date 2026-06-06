<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\RequestField;

/**
 * Turns a list of `#[RequestField]`s into the `properties` + `required` parts of an object schema.
 * Shared by {@see \Radiergummi\OpenApi\Plugins\Core\Resolvers\RequestFieldRequestSchemaResolver}
 * and {@see \Radiergummi\OpenApi\Plugins\Core\Resolvers\DiscriminatedRequestSchemaResolver}.
 *
 * @internal
 */
final readonly class RequestFieldObjectBuilder
{
    /**
     * @param iterable<RequestField> $fields
     *
     * @return array{0: list<OA\Property>, 1: list<string>} `[properties, required]`
     */
    public static function propertiesAndRequired(iterable $fields): array
    {
        /** @var list<OA\Property> $properties */
        $properties = [];

        /** @var list<string> $required */
        $required = [];

        foreach ($fields as $field) {
            if ($field->name === null || $field->name === '') {
                continue;
            }

            $property = new OA\Property(['property' => $field->name]);
            $field->descriptor()->applyTo($property);
            $properties[] = $property;

            if ($field->required === true) {
                $required[] = $field->name;
            }
        }

        return [$properties, $required];
    }
}
