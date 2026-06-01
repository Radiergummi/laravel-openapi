<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use OpenApi\Annotations as OA;
use OpenApi\Generator;

use function in_array;
use function is_array;
use function is_string;

/**
 * Mutable accumulator for the JSON Schema fields derived from a single Laravel validation field's
 * rule list.
 *
 * All fields default to null / false — null means "not set" and causes the corresponding OpenAPI
 * annotation field to be omitted (preserving the annotation's `Generator::UNDEFINED` sentinel).
 */
final class FieldDescriptor
{
    /**
     * JSON Schema `type` — string|integer|number|boolean|array|null
     */
    public ?string $type = null;

    /**
     * JSON Schema `format` — email|uri|uuid|binary|date|date-time|ip|ipv4|ipv6|null
     */
    public ?string $format = null;

    /**
     * Whether the field is nullable (`nullable` rule was present).
     */
    public bool $nullable = false;

    /**
     * Tristate signal for the rules-derived required state.
     * - `null` → rules said nothing about required (caller must not change the structurally-derived state).
     * - `true` → rules explicitly say the field is required (`required` / `present`).
     * - `false` → rules explicitly say the field is optional (`sometimes`).
     */
    public ?bool $required = null;

    /**
     * Whether the field represents a file upload (`file` or `image` rule).
     */
    public bool $isFile = false;

    /**
     * @var null|list<float|int|string> Enum values from `in:a,b,c`, `Rule::in()`, or `Rule::enum()`.
     */
    public ?array $enum = null;

    /**
     * Minimum string length / numeric minimum / array minItems (string context).
     */
    public ?int $minLength = null;

    /**
     * Maximum string length / numeric maximum / array maxItems (string context).
     */
    public ?int $maxLength = null;

    /**
     * Numeric minimum (set when type is integer or number).
     */
    public int|float|null $minimum = null;

    /**
     * Numeric maximum (set when type is integer or number).
     */
    public int|float|null $maximum = null;

    /**
     * Array minimum items (set when type is array).
     */
    public ?int $minItems = null;

    /**
     * Array maximum items (set when type is array).
     */
    public ?int $maxItems = null;

    /**
     * ECMA regex pattern (from `regex:` rule, delimiters stripped).
     */
    public ?string $pattern = null;

    /**
     * Human-readable description appended to the schema (used for constraints that have no
     * direct JSON Schema equivalent, e.g. Password character-class requirements).
     */
    public ?string $description = null;

    /**
     * Copies set descriptor fields onto `$target`.
     *
     * Accepts any {@see OA\Schema} subclass (`OA\Property`, `OA\Items`, etc.) since they all share
     * the same field bag for type/format/constraints.
     *
     * - `$overwrite = true`  (build-from-scratch): always set when the descriptor has a non-null /
     *   non-false value.
     * - `$overwrite = false` (merge): only set fields that are still `Generator::UNDEFINED` on
     *   `$target` - preserves values already established by a prior type pass.
     *
     * Constraint fields (minLength, maxLength, minimum, maximum, minItems, maxItems) are always
     * written when non-null because the type pass never sets them.
     *
     * Nullable: only set to `true` when descriptor->nullable is true AND `$target->nullable` is
     * still `Generator::UNDEFINED` (we must not override a `false` set by the type pass).
     *
     * `required` is intentionally NOT touched, that is a list-level concern owned by the caller.
     */
    public function applyTo(OA\Schema $target, bool $overwrite = true): void
    {

        // When merging (overwrite: false), skip type and nullable if the target already expresses
        // its type via oneOf/allOf/anyOf — those compositions were laid down by the type-resolution
        // pass and must not be clobbered by the validation-rules pass (e.g. Spatie Data emits
        // type:'array' for nested Data classes even though the resolved schema is a $ref-in-oneOf).
        $alreadyComposed = !$overwrite
            && (!Generator::isDefault($target->oneOf)
                || !Generator::isDefault($target->allOf)
                || !Generator::isDefault($target->anyOf));

        if (!$alreadyComposed && $this->type !== null && ($overwrite || Generator::isDefault($target->type))) {
            $target->type = $this->type;

            // swagger-php requires every `type: array` schema to carry an `items` annotation.
            // Inject an empty fallback when no items has been set by the type-resolution pass or
            // a foo.* wildcard rule. This covers both OA\Property and OA\Items targets.
            if ($this->type === 'array' && Generator::isDefault($target->items)) {
                $target->items = new OA\Items([]);
            }
        }

        if ($this->format !== null && ($overwrite || Generator::isDefault($target->format))) {
            $target->format = $this->format;
        }

        if ($this->enum !== null && ($overwrite || Generator::isDefault($target->enum))) {
            $target->enum = $this->enum;
        }

        if ($this->pattern !== null && ($overwrite || Generator::isDefault($target->pattern))) {
            $target->pattern = $this->pattern;
        }

        if ($this->description !== null && ($overwrite || Generator::isDefault($target->description))) {
            $target->description = $this->description;
        }

        // Constraints — always write when non-null (type pass never sets these).
        if ($this->minLength !== null) {
            $target->minLength = $this->minLength;
        }

        if ($this->maxLength !== null) {
            $target->maxLength = $this->maxLength;
        }

        if ($this->minimum !== null) {
            $target->minimum = $this->minimum;
        }

        if ($this->maximum !== null) {
            $target->maximum = $this->maximum;
        }

        if ($this->minItems !== null) {
            $target->minItems = $this->minItems;
        }

        if ($this->maxItems !== null) {
            $target->maxItems = $this->maxItems;
        }

        // Nullable (OAS 3.1): express nullability without the removed `nullable` keyword.
        //
        // - Scalar types (string/integer/number/boolean): widen type to an array including 'null',
        //   e.g. type: ['string', 'null']. This is valid OAS 3.1 and swagger-php accepts it.
        // - Structured types (array/object) or schemas carrying items/properties: swagger-php
        //   strictly requires type === 'array' (exact string) when OA\Items is present. Widening
        //   to ['array','null'] breaks that check. Instead, move the current type+items onto a
        //   oneOf inner schema so the outer schema carries oneOf and the inner keeps type:'array'.
        // - Already composed (oneOf/allOf/anyOf already set by the type-resolution pass): skip —
        //   the nullable wrapping was already applied upstream (e.g. via NullableSchema::wrap).
        if ($this->nullable && !$alreadyComposed) {
            if (is_string($target->type) && !Generator::isDefault($target->type)) {
                if (in_array($target->type, ['array', 'object'], strict: true)) {
                    // Structured type: pull type (and structured keywords if present) into a oneOf
                    // inner schema so the outer schema carries oneOf and the inner keeps the type.
                    $inner = new OA\Schema(['type' => $target->type]);

                    if (!Generator::isDefault($target->items)) {
                        $inner->items = $target->items;
                        $target->items = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                    }

                    if (!Generator::isDefault($target->properties)) {
                        $inner->properties = $target->properties;
                        $target->properties = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                    }

                    if (!Generator::isDefault($target->additionalProperties)) {
                        $inner->additionalProperties = $target->additionalProperties;
                        $target->additionalProperties = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                    }

                    if (!Generator::isDefault($target->required)) {
                        $inner->required = $target->required;
                        $target->required = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                    }

                    $target->type = Generator::UNDEFINED;
                    $target->oneOf = [
                        $inner,
                        new OA\Schema(['type' => 'null']),
                    ];
                } else {
                    // Scalar type: widen to a type array.
                    $target->type = [$target->type, 'null'];
                }
            } elseif (is_array($target->type) && !in_array('null', $target->type, strict: true)) {
                $target->type = [...$target->type, 'null'];
            } elseif (Generator::isDefault($target->type)) {
                $target->type = ['null'];
            }
        }
    }
}
