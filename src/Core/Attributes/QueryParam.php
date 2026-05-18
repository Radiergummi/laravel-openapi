<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;
use BackedEnum;

/**
 * Documents an ad-hoc query string parameter that isn't covered by the
 * JSON:API filter/sort/include/page extraction.
 *
 * Use this for endpoints whose query parameters are read directly off the
 * request (`$request->query('q')`) or driven by a non-JSON:API
 * form request. Each attribute defines one parameter; the attribute is
 * repeatable on both class and method targets.
 *
 * Class-level entries apply to every action on the controller; method-level
 * entries are appended afterwards. When the same `name` appears at both
 * levels, the method-level entry wins.
 *
 * The `in` location is always `query` — it is not exposed as a constructor
 * parameter.
 *
 * ```php
 * #[QueryParam('q', description: 'Free-text search query.', example: 'cnc machining')]
 * #[QueryParam('limit', type: 'integer', default: 25, maximum: 100)]
 * public function search(Request $request): JsonResponse { … }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class QueryParam extends FieldAttribute
{
    /**
     * @param null|list<BackedEnum|int|string> $enum Allowed values; renders as a dropdown.
     */
    public function __construct(
        public string $name,
        public bool $required = false,
        public bool $deprecated = false,
        ?string $title = null,
        ?string $description = null,
        mixed $example = null,
        ?string $type = null,
        ?string $format = null,
        ?bool $nullable = null,
        mixed $default = null,
        ?array $enum = null,
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
        );
    }
}
