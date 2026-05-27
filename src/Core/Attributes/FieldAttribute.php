<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use BackedEnum;
use Radiergummi\OpenApi\Core\Attributes\Support\DescriptionDirectives;
use Radiergummi\OpenApi\Core\Attributes\Support\FieldDefault;
use Radiergummi\OpenApi\Core\Generator\SchemaDescriptor;

use function array_values;

/**
 * Abstract base for the scope-specific field attributes.
 *
 * Holds the full JSON-Schema parameter surface and the {@see self::descriptor()}
 * mapping. It is intentionally NOT an `#[Attribute]` — only its concrete
 * subclasses ({@see PathParam}, {@see QueryParam}, {@see RequestField},
 * {@see ResponseField}) may be written in source. Each subclass exposes only the
 * parameters meaningful in its scope and forwards them to this constructor.
 *
 * `example` and `enum` accept the {@see FieldDefault::Unset} sentinel as their default. This lets
 * {@see descriptor()} tell "author did not pass the argument" apart from "author explicitly passed
 * null" — only the former falls back to a value supplied by an inline description directive.
 */
abstract readonly class FieldAttribute
{
    /**
     * @param null|non-empty-string                               $title
     * @param null|non-empty-string                               $description
     * @param null|class-string|OpenApiPrimitiveType              $type
     * @param null|non-empty-string                               $format
     * @param null|array<int, BackedEnum|int|string>|FieldDefault $enum
     * @param null|int<0, max>                                    $minLength
     * @param null|int<0, max>                                    $maxLength
     * @param null|non-empty-string                               $pattern
     * @param null|int<0, max>                                    $minItems
     * @param null|int<0, max>                                    $maxItems
     * @param bool                                                $conditional When true, the field is kept in
     *                                                                         `properties` but removed from `required`
     *                                                                         — used by response fields emitted via
     *                                                                         `$this->when()` / `$this->whenLoaded()`.
     */
    protected function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public mixed $example = FieldDefault::Unset,
        public ?string $type = null,
        public ?string $format = null,
        public ?bool $nullable = null,
        public mixed $default = null,
        public array|FieldDefault|null $enum = FieldDefault::Unset,
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

    /**
     * Returns the explicit `example:` argument, or `null` when the author did not pass one.
     *
     * `null` is the conventional way to suppress a value in PHP, so this collapses the sentinel
     * "unset" state to `null` for downstream readers (lint rules, AST walkers) that don't care
     * about distinguishing the two.
     */
    public function explicitExample(): mixed
    {
        return $this->example instanceof FieldDefault ? null : $this->example;
    }

    /**
     * Returns the explicit `enum:` argument, or `null` when the author did not pass one.
     *
     * @return null|array<int, BackedEnum|int|string>
     */
    public function explicitEnum(): ?array
    {
        return $this->enum instanceof FieldDefault ? null : $this->enum;
    }

    public function descriptor(): SchemaDescriptor
    {
        $parsed = DescriptionDirectives::parse($this->description);

        // Precedence: explicit attribute argument always wins over a description directive — even
        // when the explicit argument is `null`, which is the conventional way to suppress a value
        // in PHP. `@no-example` only suppresses an `@example` directive on the same description; it
        // does not override an explicit `example:` argument.
        $example = $this->example instanceof FieldDefault
            ? ($parsed->suppressExample ? null : $parsed->example)
            : $this->example;

        $enum = $this->enum instanceof FieldDefault
            ? $parsed->enum
            : ($this->enum === null ? null : array_values($this->enum));

        return new SchemaDescriptor(
            title: $this->title,
            description: $parsed->cleanDescription,
            example: $example,
            type: $this->type,
            format: $this->format,
            nullable: $this->nullable,
            default: $this->default,
            enum: $enum,
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
