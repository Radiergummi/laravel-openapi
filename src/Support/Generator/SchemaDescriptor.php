<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use BackedEnum;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\PathParam;
use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Attributes\ResponseField;

/**
 * Carries all JSON-Schema field metadata expressible via scoped field
 * attributes ({@see RequestField}, {@see ResponseField}, {@see PathParam}, {@see QueryParam}).
 *
 * Null means "not set": {@see toOpenApi()} omits null keys so extractors' inferred values are
 * preserved. `enum` entries are any JSON-Schema scalar (`bool`, `float`, `int`, `string`) or a
 * {@see BackedEnum} case; {@see toOpenApi()} converts BackedEnum cases to their backing values.
 *
 * @internal
 */
final readonly class SchemaDescriptor
{
    /**
     * @param null|list<BackedEnum|bool|float|int|string> $enum
     */
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public mixed $example = null,
        public ?string $type = null,
        public ?string $format = null,
        public ?string $items = null,
        public ?bool $nullable = null,
        public mixed $default = null,
        public ?array $enum = null,
        public int|float|null $minimum = null,
        public int|float|null $maximum = null,
        public int|float|null $exclusiveMinimum = null,
        public int|float|null $exclusiveMaximum = null,
        public int|float|null $multipleOf = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public ?bool $uniqueItems = null,
        public ?bool $readOnly = null,
        public ?bool $writeOnly = null,
    ) {}

    /**
     * Builds a standalone `OA\Schema` from this descriptor, applying the OAS 3.1
     * `type: [..., 'null']` shape when `$this->nullable === true`. An untyped descriptor
     * produces an open schema (no `type`) rather than defaulting to `string`.
     *
     * `toOpenApi()` deliberately omits `nullable`; this helper is the canonical
     * place to apply it for callers that produce a `Schema` (parameter resolvers)
     * rather than a `Property` ({@see applyTo()}).
     */
    public function toSchema(): OA\Schema
    {
        // No `type` seed: an untyped descriptor yields an open schema, not a string one. A typed
        // descriptor carries its own `type` through toOpenApi().
        $schema = new OA\Schema($this->toOpenApi());

        if (($items = $this->itemsSchema()) !== null) {
            $schema->items = $items;
        }

        if ($this->nullable === true) {
            NullableSchema::applyTo($schema);
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    public function toOpenApi(): array
    {
        $out = array_filter([
            'title' => $this->title,
            'description' => $this->description,
            'example' => $this->example,
            'type' => $this->type,
            'format' => $this->format,
            'default' => $this->default,
            'minimum' => $this->minimum,
            'maximum' => $this->maximum,
            'exclusiveMinimum' => $this->exclusiveMinimum,
            'exclusiveMaximum' => $this->exclusiveMaximum,
            'multipleOf' => $this->multipleOf,
            'minLength' => $this->minLength,
            'maxLength' => $this->maxLength,
            'pattern' => $this->pattern,
            'minItems' => $this->minItems,
            'maxItems' => $this->maxItems,
            'uniqueItems' => $this->uniqueItems,
            'readOnly' => $this->readOnly,
            'writeOnly' => $this->writeOnly,
        ], static fn($value) => $value !== null);

        if ($this->enum !== null) {
            $out['enum'] = array_map(
                static fn(BackedEnum|bool|float|int|string $case): bool|float|int|string
                    => $case instanceof BackedEnum ? $case->value : $case,
                $this->enum,
            );
        }

        return $out;
    }

    /**
     * The `items` schema for an `array` type. Always present for an array (a permissive `{}` when
     * no element type is declared), because swagger-php rejects an items-less array and would
     * hard-fail generation.
     */
    private function itemsSchema(): ?OA\Items
    {
        if ($this->type !== 'array') {
            return null;
        }

        return $this->items !== null
            ? new OA\Items(['type' => $this->items])
            : new OA\Items([]);
    }

    /**
     * Applies this descriptor's non-null fields onto an existing `OA\Property`, and switches the
     * property to the nullable shape when `$this->nullable === true`.
     *
     * Used by the field-attribute consumers ({@see RequestField}, {@see ResponseField},
     * {@see ResourceField}, {@see RequestField}-on-constants) to mutate a `new OA\Property([…])`
     * into its fully-described form without each caller re-implementing the `toOpenApi()` + nullable
     * dance.
     */
    public function applyTo(OA\Property $property): void
    {
        foreach ($this->toOpenApi() as $field => $value) {
            $property->{$field} = $value;
        }

        if (($items = $this->itemsSchema()) !== null) {
            $property->items = $items;
        }

        if ($this->nullable === true) {
            NullableSchema::applyTo($property);
        }
    }
}
