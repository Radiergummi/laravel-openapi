<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

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
     * `type: [..., 'null']` shape when `$this->nullable === true`.
     *
     * `toOpenApi()` deliberately omits `nullable`; this helper is the canonical
     * place to apply it for callers that produce a `Schema` (parameter resolvers)
     * rather than a `Property` ({@see applyTo()}).
     */
    public function toSchema(): OA\Schema
    {
        $schema = new OA\Schema(['type' => 'string', ...$this->toOpenApi()]);

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

        if ($this->nullable === true) {
            NullableSchema::applyTo($property);
        }
    }
}
