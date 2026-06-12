<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use OpenApi\Annotations as OA;

use function get_object_vars;
use function in_array;
use function Radiergummi\OpenApi\is_undefined;

/**
 * Copies the defined JSON-Schema fields of one {@see OA\Schema} onto another. Since
 * `OA\Property` and `OA\Items` both extend `OA\Schema`, this is how a resolved schema is
 * re-homed as a named property or an array item.
 *
 * swagger-php internals (underscore-prefixed fields) and the component-key fields (`property`,
 * `schema`) are skipped, as are {@see is_undefined()} values.
 *
 * @internal
 */
final class SchemaFieldCopier
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function copy(OA\Schema $source, OA\Schema $target): void
    {
        foreach (get_object_vars($source) as $field => $value) {
            if (is_undefined($value) || $field[0] === '_' || in_array($field, ['property', 'schema'], strict: true)) {
                continue;
            }

            $target->{$field} = $value;
        }
    }
}
