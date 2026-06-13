<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Support\Generator\NullableSchema;

use function in_array;
use function is_array;
use function is_string;
use function Radiergummi\OpenApi\is_defined;
use function Radiergummi\OpenApi\is_undefined;

/**
 * Mutable accumulator for the JSON Schema fields derived from a single Laravel validation field's
 * rule list.
 *
 * All fields default to null / false — null means "not set" and causes the corresponding OpenAPI
 * annotation field to be omitted (preserving the annotation's `Generator::UNDEFINED` sentinel).
 *
 * @internal
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
     * A `$ref` pointer (`#/components/schemas/…`) the field resolves to instead of an inline schema.
     * Set when a `Rule::enum()` rule was promoted to a shared reusable enum component. When present,
     * it takes precedence over inline type/enum keywords in {@see applyTo()}.
     */
    public ?string $ref = null;

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
     * Numeric multiple-of constraint (from the `multiple_of:N` rule).
     */
    public int|float|null $multipleOf = null;

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
     * Example value for the field (e.g. supplied by a self-documenting custom rule). `null` means
     * "not set" — distinct from a deliberate example, which `SelfDocumentingRule` cannot express.
     */
    public mixed $example = null;

    /**
     * Nested object properties, keyed by property name, derived from dotted validation keys
     * (`address.city`). Non-null marks this descriptor an `object`; each child is emitted as an
     * `OA\Property` and required children populate the object's `required` list.
     *
     * @var null|array<string, FieldDescriptor>
     */
    public ?array $properties = null;

    /**
     * Nested array element descriptor, derived from wildcard validation keys (`items.*`). Non-null
     * marks this descriptor an `array`; it is emitted as the schema's `items`.
     */
    public ?FieldDescriptor $items = null;

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
        // A `$ref` to a shared component (a promoted `Rule::enum()`) resolves the whole field: it
        // replaces inline type/enum keywords. A nullable ref widens to `oneOf: [{$ref}, {null}]` via
        // NullableSchema, since keywords alongside a `$ref` are ignored in OAS 3.1.
        if ($this->ref !== null && ($overwrite || is_undefined($target->ref))) {
            if ($this->nullable) {
                $target->oneOf = NullableSchema::wrap(new OA\Schema(['ref' => $this->ref]))->oneOf;
            } else {
                $target->ref = $this->ref;
            }

            return;
        }

        // When merging (overwrite: false), skip type and nullable if the target already expresses
        // its type via oneOf/allOf/anyOf — those compositions were laid down by the type-resolution
        // pass and must not be clobbered by the validation-rules pass (e.g. Spatie Data emits
        // type:'array' for nested Data classes even though the resolved schema is a $ref-in-oneOf).
        $alreadyComposed = !$overwrite
            && (is_defined($target->oneOf)
                || is_defined($target->allOf)
                || is_defined($target->anyOf));

        if (!$alreadyComposed && $this->type !== null && ($overwrite || is_undefined($target->type))) {
            $target->type = $this->type;

            // swagger-php requires every `type: array` schema to carry an `items` annotation.
            // Inject an empty fallback when no items has been set by the type-resolution pass or
            // a foo.* wildcard rule. This covers both OA\Property and OA\Items targets. Skip it
            // when this descriptor carries a nested items descriptor — the real items is emitted below.
            if ($this->type === 'array' && $this->items === null && is_undefined($target->items)) {
                $target->items = new OA\Items([]);
            }
        }

        if ($this->format !== null && ($overwrite || is_undefined($target->format))) {
            $target->format = $this->format;
        }

        if ($this->enum !== null && ($overwrite || is_undefined($target->enum))) {
            $target->enum = $this->enum;
        }

        if ($this->pattern !== null && ($overwrite || is_undefined($target->pattern))) {
            $target->pattern = $this->pattern;
        }

        if ($this->description !== null && ($overwrite || is_undefined($target->description))) {
            $target->description = $this->description;
        }

        if ($this->example !== null && ($overwrite || is_undefined($target->example))) {
            $target->example = $this->example;
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

        if ($this->multipleOf !== null) {
            $target->multipleOf = $this->multipleOf;
        }

        if ($this->minItems !== null) {
            $target->minItems = $this->minItems;
        }

        if ($this->maxItems !== null) {
            $target->maxItems = $this->maxItems;
        }

        // Nested object properties (from dotted validation keys). Emitted before the nullable
        // block so a nullable object's properties/required migrate into the oneOf inner schema.
        //
        // In merge mode (overwrite: false — the Spatie type-pass-first path) only fill a bare
        // object placeholder: never add properties to a schema the type pass already composed
        // (oneOf/allOf/anyOf) or expressed as a `$ref`.
        $canFillProperties = $overwrite
            || (is_undefined($target->properties) && !$alreadyComposed && is_undefined($target->ref));

        if ($this->properties !== null && $canFillProperties) {
            $childProperties = [];
            $requiredChildren = [];

            foreach ($this->properties as $name => $childDescriptor) {
                $childProperty = new OA\Property(['property' => $name]);
                $childDescriptor->applyTo($childProperty);
                $childProperties[] = $childProperty;

                if ($childDescriptor->required === true) {
                    $requiredChildren[] = $name;
                }
            }

            $target->properties = $childProperties;

            if ($requiredChildren !== [] && is_undefined($target->required)) {
                $target->required = $requiredChildren;
            }
        }

        // Nested array element (from wildcard validation keys).
        if ($this->items !== null) {
            if ($overwrite || is_undefined($target->items)) {
                $childItems = new OA\Items([]);
                $this->items->applyTo($childItems);
                $target->items = $childItems;
            } elseif ($target->items instanceof OA\Items && is_undefined($target->items->ref)) {
                // Fill the empty placeholder items swagger-php requires on every array (the
                // scalar-array case in the Spatie merge path) without clobbering a `$ref` element.
                $this->items->applyTo($target->items, overwrite: false);
            }
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
            if (is_string($target->type) && is_defined($target->type)) {
                if (in_array($target->type, ['array', 'object'], strict: true)) {
                    // Structured type: pull type (and structured keywords if present) into a oneOf
                    // inner schema so the outer schema carries oneOf and the inner keeps the type.
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

                    // Migrate validation constraint keywords into the inner branch so per-branch
                    // validators apply them. Without this they strand on the typeless outer oneOf
                    // schema where JSON Schema validators ignore them.
                    if (is_defined($target->minItems)) {
                        $inner->minItems = $target->minItems;
                        $target->minItems = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                    }

                    if (is_defined($target->maxItems)) {
                        $inner->maxItems = $target->maxItems;
                        $target->maxItems = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                    }

                    if (is_defined($target->minimum)) {
                        $inner->minimum = $target->minimum;
                        $target->minimum = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
                    }

                    if (is_defined($target->maximum)) {
                        $inner->maximum = $target->maximum;
                        $target->maximum = Generator::UNDEFINED; // @phpstan-ignore assign.propertyType (clearing the property; swagger-php uses the UNDEFINED sentinel string here)
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
                    // Scalar type: widen to a type array.
                    $target->type = [$target->type, 'null'];
                }
            } elseif (is_array($target->type) && !in_array('null', $target->type, strict: true)) {
                $target->type = [...$target->type, 'null'];
            } elseif (is_undefined($target->type)) {
                $target->type = ['null'];
            }
        }
    }
}
