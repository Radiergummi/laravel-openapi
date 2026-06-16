<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use BackedEnum;
use OpenApi\Annotations as OA;

use function is_bool;
use function Radiergummi\OpenApi\is_undefined;
use function str_starts_with;
use function substr;

/**
 * Carries JSON-Schema field metadata from scoped field attributes. Null means "not set":
 * {@see toOpenApi()} omits null keys so extractors' inferred values are preserved.
 *
 * @internal
 */
final readonly class SchemaDescriptor
{
    /**
     * @param null|list<BackedEnum|bool|float|int|string> $enum
     * @param null|array<string, mixed>                   $x                    Vendor extensions (`x-*`); keys stored
     *                                                                          stripped (swagger-php re-adds `x-` on
     *                                                                          serialize).
     * @param null|bool|string                            $additionalProperties Map-value override: bool or a type
     *                                                                          string wrapped into a value schema.
     *                                                                          `null` leaves inference untouched.
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
        public ?array $x = null,
        public bool|string|null $additionalProperties = null,
    ) {}

    /**
     * Builds a standalone `OA\Schema`, applying the OAS 3.1 `type: [..., 'null']` shape when
     * nullable. Use this for parameter resolvers; use {@see applyTo()} for properties.
     */
    public function toSchema(): OA\Schema
    {
        $schema = new OA\Schema($this->toOpenApi());

        if (($items = $this->itemsSchema()) !== null) {
            $schema->items = $items;
        }

        if ($this->nullable === true) {
            NullableSchema::applyTo($schema);
        }

        $this->applyAdditionalProperties($schema);
        $this->applyVendorExtensions($schema);

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
        ], static fn(mixed $value): bool => $value !== null);

        if ($this->enum !== null) {
            $out['enum'] = array_map(
                static fn(BackedEnum|bool|float|int|string $case): bool|float|int|string
                    => $case instanceof BackedEnum ? $case->value : $case,
                $this->enum,
            );
        }

        return $out;
    }

    /** swagger-php rejects an items-less array; falls back to a permissive `{}` when no type. */
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
     * Applies this descriptor's non-null fields onto an existing `OA\Property`, including nullable.
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

        $this->applyAdditionalProperties($property);
        $this->applyVendorExtensions($property);
    }

    /**
     * Writes `additionalProperties` onto the target unconditionally (author override wins).
     * Public for {@see UriParametersExtractor}, which builds parameter schemas directly.
     */
    public function applyAdditionalProperties(OA\Schema|OA\Property $target): void
    {
        $value = $this->additionalPropertiesValue();

        if ($value !== null) {
            $target->additionalProperties = $value;
        }
    }

    /** Bool passes through; a type string is wrapped into a value schema. `null` means "not set". */
    private function additionalPropertiesValue(): bool|OA\AdditionalProperties|null
    {
        return match (true) {
            $this->additionalProperties === null => null,
            is_bool($this->additionalProperties) => $this->additionalProperties,
            default => new OA\AdditionalProperties(['type' => $this->additionalProperties]),
        };
    }

    /**
     * Merges vendor extensions onto swagger-php's `$x` bag. Keys are stored `x-`-stripped because
     * swagger-php re-adds the prefix on serialize. Public for {@see UriParametersExtractor}.
     */
    public function applyVendorExtensions(OA\Schema|OA\Property $target): void
    {
        if ($this->x === null) {
            return;
        }

        $bag = is_undefined($target->x) ? [] : $target->x;

        foreach ($this->x as $key => $value) {
            $bag[str_starts_with($key, 'x-') ? substr($key, 2) : $key] = $value;
        }

        $target->x = $bag;
    }
}
