<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use OpenApi\Annotations as OA;
use OpenApi\Generator;

use function in_array;
use function is_array;
use function is_string;
use function Radiergummi\OpenApi\is_defined;
use function Radiergummi\OpenApi\is_undefined;

/**
 * OAS 3.1-compatible nullable schema wrapping (OAS 3.1 removed `nullable`).
 *
 * - Scalar type (`string`/`integer`/`number`/`boolean`): widened to a type array including `'null'`.
 * - Structured type (`array`/`object`) or `$ref`: wrapped in `oneOf: [<inner>, {type: 'null'}]`
 *   so that swagger-php's `OA\Items` parent check (requires `type === 'array'` as a string) passes.
 * - All other cases (no type, already composed): wrapped in `oneOf`.
 *
 * @internal
 */
final class NullableSchema
{
    /** Scalar OAS types that can be safely widened to a type array. */
    private const array SCALAR_TYPES = ['string', 'integer', 'number', 'boolean'];

    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * Returns a nullable copy of `$schema` using the OAS 3.1 idiom. Never mutates the input.
     */
    public static function wrap(OA\Schema $schema): OA\Schema
    {
        // Extra keywords alongside $ref are ignored in OAS 3.1; must use oneOf.
        if (is_defined($schema->ref) && is_string($schema->ref)) {
            return new OA\Schema([
                'oneOf' => [
                    new OA\Schema(['ref' => $schema->ref]),
                    new OA\Schema(['type' => 'null']),
                ],
            ]);
        }

        // Scalar type: widen to include 'null'.
        if (
            is_defined($schema->type)
            && is_string($schema->type)
            && in_array($schema->type, self::SCALAR_TYPES, strict: true)
        ) {
            $clone = clone $schema;
            $clone->type = [$schema->type, 'null'];

            return $clone;
        }

        // Already a type array: append 'null' if absent (only safe when all-scalar).
        if (is_defined($schema->type) && is_array($schema->type)) {
            $hasStructured = false;

            foreach ($schema->type as $type) {
                if ($type !== 'null' && !in_array($type, self::SCALAR_TYPES, strict: true)) {
                    $hasStructured = true;

                    break;
                }
            }

            if (!$hasStructured) {
                $clone = clone $schema;

                if (!in_array('null', $schema->type, strict: true)) {
                    $clone->type = [...$schema->type, 'null'];
                }

                return $clone;
            }
        }

        // Structured/untyped: wrap in oneOf to keep type: 'array' as a string (OA\Items check).
        return new OA\Schema([
            'oneOf' => [
                $schema,
                new OA\Schema(['type' => 'null']),
            ],
        ]);
    }

    /**
     * Applies OAS 3.1 nullability to `$target` in place.
     *
     * Use when the schema is already held by reference in a collection and cannot be replaced.
     */
    public static function applyTo(OA\Schema $target): void
    {
        // Extra keywords alongside $ref are ignored in OAS 3.1; must use oneOf.
        if (is_defined($target->ref) && is_string($target->ref)) {
            $target->oneOf = [
                new OA\Schema(['ref' => $target->ref]),
                new OA\Schema(['type' => 'null']),
            ];
            $target->ref = Generator::UNDEFINED;

            return;
        }

        if (is_string($target->type) && is_defined($target->type)) {
            if (in_array($target->type, ['array', 'object'], strict: true)) {
                $inner = new OA\Schema(['type' => $target->type]);

                if (is_defined($target->items)) {
                    $inner->items = $target->items;
                    $target->items = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                }

                if (is_defined($target->properties)) {
                    $inner->properties = $target->properties;
                    $target->properties = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                }

                if (is_defined($target->additionalProperties)) {
                    $inner->additionalProperties = $target->additionalProperties;
                    $target->additionalProperties = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                }

                if (is_defined($target->required)) {
                    $inner->required = $target->required;
                    $target->required = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                }

                // Validation constraints strand on a typeless outer schema; move them inside.
                if (is_defined($target->minItems)) {
                    $inner->minItems = $target->minItems;
                    $target->minItems = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                }

                if (is_defined($target->maxItems)) {
                    $inner->maxItems = $target->maxItems;
                    $target->maxItems = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                }

                if (is_defined($target->uniqueItems)) {
                    $inner->uniqueItems = $target->uniqueItems;
                    $target->uniqueItems = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                }

                if (is_defined($target->minimum)) {
                    $inner->minimum = $target->minimum;
                    $target->minimum = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                }

                if (is_defined($target->maximum)) {
                    $inner->maximum = $target->maximum;
                    $target->maximum = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                }

                if (is_defined($target->exclusiveMinimum)) {
                    $inner->exclusiveMinimum = $target->exclusiveMinimum;
                    $target->exclusiveMinimum = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                }

                if (is_defined($target->exclusiveMaximum)) {
                    $inner->exclusiveMaximum = $target->exclusiveMaximum;
                    $target->exclusiveMaximum = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                }

                if (is_defined($target->minLength)) {
                    $inner->minLength = $target->minLength;
                    $target->minLength = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                }

                if (is_defined($target->maxLength)) {
                    $inner->maxLength = $target->maxLength;
                    $target->maxLength = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                }

                if (is_defined($target->multipleOf)) {
                    $inner->multipleOf = $target->multipleOf;
                    $target->multipleOf = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                }

                if (is_defined($target->pattern)) {
                    $inner->pattern = $target->pattern;
                    $target->pattern = Generator::UNDEFINED;
                }

                if (is_defined($target->format)) {
                    $inner->format = $target->format;
                    $target->format = Generator::UNDEFINED;
                }

                if (is_defined($target->enum)) {
                    $inner->enum = $target->enum;
                    $target->enum = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                }

                $target->type = Generator::UNDEFINED;
                $target->oneOf = [
                    $inner,
                    new OA\Schema(['type' => 'null']),
                ];
            } else {
                $target->type = [$target->type, 'null'];
            }
        } elseif (is_array($target->type) && !in_array('null', $target->type, strict: true)) {
            $target->type = [...$target->type, 'null'];
        } elseif (is_undefined($target->type)) {
            $target->type = ['null'];
        }
    }
}
