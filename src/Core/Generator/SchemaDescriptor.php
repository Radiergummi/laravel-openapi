<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Generator;

use BackedEnum;

/**
 * Carries all JSON-Schema field metadata expressible via scoped field
 * attributes ({@see RequestField}, {@see ResponseField}, {@see PathParam}, {@see QueryParam}).
 *
 * Null means "not set": {@see toOpenApi()} omits null keys so extractors' inferred values are
 * preserved. `enum` entries may be strings, ints, or {@see BackedEnum} cases; {@see toOpenApi()}
 * converts BackedEnum cases to their backing values.
 */
final readonly class SchemaDescriptor
{
    /**
     * @param null|list<BackedEnum|int|string> $enum
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
        ], fn($value) => $value !== null);

        if ($this->enum !== null) {
            $out['enum'] = array_map(
                static fn(BackedEnum|int|string $case): int|string
                => $case instanceof BackedEnum ? $case->value : $case,
                $this->enum,
            );
        }

        return $out;
    }
}
