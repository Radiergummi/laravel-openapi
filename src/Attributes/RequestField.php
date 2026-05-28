<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;
use BackedEnum;
use Radiergummi\OpenApi\Support\Attributes\FieldDefault;

/**
 * Documents a request-body input field.
 *
 * Place on a Spatie Data class property / promoted constructor parameter, or on a FormRequest
 * `PARAM_*` class constant. Request fields support `writeOnly` but not `readOnly` (a request field
 * is never read-only).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER | Attribute::TARGET_CLASS_CONSTANT)]
final readonly class RequestField extends FieldAttribute
{
    /**
     * @param null|non-empty-string                               $title
     * @param null|non-empty-string                               $description
     * @param null|OpenApiPrimitiveType                           $type
     * @param null|non-empty-string                               $format
     * @param null|array<int, BackedEnum|int|string>|FieldDefault $enum
     * @param null|int<0, max>                                    $minLength
     * @param null|int<0, max>                                    $maxLength
     * @param null|non-empty-string                               $pattern
     * @param null|int<0, max>                                    $minItems
     * @param null|int<0, max>                                    $maxItems
     */
    public function __construct(
        ?string $title = null,
        ?string $description = null,
        mixed $example = FieldDefault::Unset,
        ?string $type = null,
        ?string $format = null,
        ?bool $nullable = null,
        mixed $default = null,
        array|FieldDefault|null $enum = FieldDefault::Unset,
        int|float|null $minimum = null,
        int|float|null $maximum = null,
        int|float|null $exclusiveMinimum = null,
        int|float|null $exclusiveMaximum = null,
        int|float|null $multipleOf = null,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $pattern = null,
        ?int $minItems = null,
        ?int $maxItems = null,
        ?bool $uniqueItems = null,
        ?bool $writeOnly = null,
    ) {
        parent::__construct(
            title: $title,
            description: $description,
            example: $example,
            type: $type,
            format: $format,
            nullable: $nullable,
            default: $default,
            enum: $enum,
            minimum: $minimum,
            maximum: $maximum,
            exclusiveMinimum: $exclusiveMinimum,
            exclusiveMaximum: $exclusiveMaximum,
            multipleOf: $multipleOf,
            minLength: $minLength,
            maxLength: $maxLength,
            pattern: $pattern,
            minItems: $minItems,
            maxItems: $maxItems,
            uniqueItems: $uniqueItems,
            writeOnly: $writeOnly,
        );
    }
}
