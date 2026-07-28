<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Support\Generator\NullableSchema;

use function Radiergummi\OpenApi\is_defined;
use function Radiergummi\OpenApi\is_undefined;

/**
 * Mutable accumulator for JSON Schema fields derived from a single validation field's rule list.
 * Null means "not set"; the corresponding OpenAPI annotation field stays at `Generator::UNDEFINED`.
 *
 * @internal
 */
final class FieldDescriptor
{
    /** JSON Schema `type`: string|integer|number|boolean|array|null */
    public ?string $type = null;

    /** JSON Schema `format`: email|uri|uuid|binary|date|date-time|ip|ipv4|ipv6|null */
    public ?string $format = null;

    /** Whether the `nullable` rule was present. */
    public bool $nullable = false;

    /**
     * Rules-derived required state: `null` = rules silent (don't change structural state),
     * `true` = required/present, `false` = sometimes.
     */
    public ?bool $required = null;

    /** Whether the field represents a file upload (`file` or `image` rule). */
    public bool $isFile = false;

    /**
     * @var null|list<float|int|string> Enum values from `in:a,b,c`, `Rule::in()`, or `Rule::enum()`.
     */
    public ?array $enum = null;

    /**
     * A `$ref` pointer to a shared enum component (promoted from `Rule::enum()`).
     * When set, takes precedence over inline type/enum in {@see applyTo()}.
     */
    public ?string $ref = null;

    /** Minimum string length / array minItems. */
    public ?int $minLength = null;

    /** Maximum string length / array maxItems. */
    public ?int $maxLength = null;

    public int|float|null $minimum = null;

    public int|float|null $maximum = null;

    /** From the `multiple_of:N` rule. */
    public int|float|null $multipleOf = null;

    /** Minimum items (array type). */
    public ?int $minItems = null;

    /** Maximum items (array type). */
    public ?int $maxItems = null;

    /** ECMA regex pattern from the `regex:` rule (delimiters stripped). */
    public ?string $pattern = null;

    /**
     * Description for constraints with no direct JSON Schema equivalent
     * (e.g., Password character-class requirements).
     */
    public ?string $description = null;

    /** Example value supplied by a self-documenting custom rule; `null` means not set. */
    public mixed $example = null;

    /**
     * Nested object properties from dotted validation keys (`address.city`).
     * Non-null marks this descriptor as `object`.
     *
     * @var null|array<string, FieldDescriptor>
     */
    public ?array $properties = null;

    /**
     * Nested array element descriptor from wildcard validation keys (`items.*`).
     * Non-null marks this descriptor as `array`.
     */
    public ?FieldDescriptor $items = null;

    /**
     * Copies set descriptor fields onto `$target`.
     *
     * `$overwrite = true`: always write non-null/non-false values.
     * `$overwrite = false`: only fill fields still at `Generator::UNDEFINED`.
     * Constraint fields are always written when non-null.
     * `required` is not touched; that is a list-level concern owned by the caller.
     */
    public function applyTo(OA\Schema $target, bool $overwrite = true): void
    {
        // A $ref replaces inline type/enum. Nullable refs widen to oneOf via NullableSchema
        // because OAS 3.1 ignores keywords alongside a $ref.
        if ($this->ref !== null && ($overwrite || is_undefined($target->ref))) {
            if ($this->nullable) {
                // Indexing the result is safe only because a bare $ref always splits: wrap()
                // leaves oneOf undefined for schemas it finds nothing to move out of.
                $target->oneOf = NullableSchema::wrap(new OA\Schema(['ref' => $this->ref]))->oneOf;
            } else {
                $target->ref = $this->ref;
            }

            return;
        }

        // In merge mode, skip type/nullable when the target is already composed; those schemas
        // were laid down by the type-resolution pass and must not be clobbered.
        $alreadyComposed = !$overwrite
            && (is_defined($target->oneOf)
                || is_defined($target->allOf)
                || is_defined($target->anyOf));

        if (!$alreadyComposed && $this->type !== null && ($overwrite || is_undefined($target->type))) {
            $target->type = $this->type;

            // swagger-php requires items on every array schema; inject an empty fallback if none was set.
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

        // Constraint fields: always write when non-null.
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

        // Nested object properties. Emitted before the nullable block so they migrate into the
        // oneOf inner schema. In merge mode, only fill a bare placeholder.
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

        $targetIsMap = $target->type === 'object' && is_defined($target->additionalProperties);

        if ($this->items !== null && !$targetIsMap) {
            if ($overwrite || is_undefined($target->items)) {
                $childItems = new OA\Items([]);
                $this->items->applyTo($childItems);
                $target->items = $childItems;
            } elseif ($target->items instanceof OA\Items && is_undefined($target->items->ref)) {
                // Fill the empty placeholder without clobbering an existing $ref.
                $this->items->applyTo($target->items, overwrite: false);
            }
        }

        // Skip when already composed: nullable wrapping was applied upstream.
        if ($this->nullable && !$alreadyComposed) {
            NullableSchema::applyTo($target);
        }
    }
}
