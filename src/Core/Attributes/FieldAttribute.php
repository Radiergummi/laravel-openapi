<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Radiergummi\OpenApi\Core\Generator\SchemaDescriptor;
use BackedEnum;

/**
 * Abstract base for the scope-specific field attributes.
 *
 * Holds the full JSON-Schema parameter surface and the {@see self::descriptor()}
 * mapping. It is intentionally NOT an `#[Attribute]` — only its concrete
 * subclasses ({@see PathParam}, {@see QueryParam}, {@see RequestField},
 * {@see ResponseField}) may be written in source. Each subclass exposes only the
 * parameters meaningful in its scope and forwards them to this constructor.
 */
abstract readonly class FieldAttribute
{
    /**
     * @param null|list<BackedEnum|int|string> $enum
     * @param bool                             $conditional When true, the field is kept in
     *                                                      `properties` but removed from `required`
     *                                                      — used by response fields emitted via
     *                                                      `$this->when()` / `$this->whenLoaded()`.
     */
    protected function __construct(
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
        public bool $conditional = false,
    ) {}

    public function descriptor(): SchemaDescriptor
    {
        return new SchemaDescriptor(
            title: $this->title,
            description: $this->description,
            example: $this->example,
            type: $this->type,
            format: $this->format,
            nullable: $this->nullable,
            default: $this->default,
            enum: $this->enum,
            minimum: $this->minimum,
            maximum: $this->maximum,
            exclusiveMinimum: $this->exclusiveMinimum,
            exclusiveMaximum: $this->exclusiveMaximum,
            multipleOf: $this->multipleOf,
            minLength: $this->minLength,
            maxLength: $this->maxLength,
            pattern: $this->pattern,
            minItems: $this->minItems,
            maxItems: $this->maxItems,
            uniqueItems: $this->uniqueItems,
            readOnly: $this->readOnly,
            writeOnly: $this->writeOnly,
        );
    }
}
