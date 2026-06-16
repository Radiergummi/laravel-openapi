<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;
use BackedEnum;
use Radiergummi\OpenApi\Support\Attributes\FieldDefault;

/**
 * Documents an ad-hoc query parameter not covered by JSON:API filter/sort/include/page extraction —
 * for parameters read directly off the request or driven by a non-JSON:API form request.
 * Repeatable; method-level wins over class-level on `name` collision.
 *
 * ```php
 * #[QueryParam('q', description: 'Free-text search query.', example: 'cnc machining')]
 * #[QueryParam('limit', type: 'integer', default: 25, maximum: 100)]
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class QueryParam extends FieldAttribute
{
    /**
     * @param non-empty-string                                                             $name
     * @param null|non-empty-string                                                        $title
     * @param null|non-empty-string                                                        $description
     * @param null|OpenApiPrimitiveType                                                    $type
     * @param null|non-empty-string                                                        $format
     * @param null|array<int, BackedEnum|int|string>|class-string<BackedEnum>|FieldDefault $enum
     *                                                                                                           Allowed values, or a
     *                                                                                                           backed-enum class-string;
     *                                                                                                           renders as a dropdown.
     * @param null|int<0, max>                                                             $minLength
     * @param null|int<0, max>                                                             $maxLength
     * @param null|non-empty-string                                                        $pattern
     * @param null|int<0, max>                                                             $minItems
     * @param null|int<0, max>                                                             $maxItems
     * @param null|array<string, mixed>                                                    $x                    Vendor
     *                                                                                                           extensions (`x-*`).
     * @param null|bool|string                                                             $additionalProperties Map-value
     *                                                                                                           override.
     */
    public function __construct(
        public string $name,
        public bool $required = false,
        public bool $deprecated = false,
        ?string $title = null,
        ?string $description = null,
        mixed $example = FieldDefault::Unset,
        ?string $type = null,
        ?string $format = null,
        ?string $items = null,
        ?bool $nullable = null,
        mixed $default = null,
        array|string|FieldDefault|null $enum = FieldDefault::Unset,
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
        ?array $x = null,
        bool|string|null $additionalProperties = null,
    ) {
        parent::__construct(
            title: $title,
            description: $description,
            example: $example,
            type: $type,
            format: $format,
            items: $items,
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
            x: $x,
            additionalProperties: $additionalProperties,
        );
    }
}
