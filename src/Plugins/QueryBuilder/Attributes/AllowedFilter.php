<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes;

use Attribute;
use BackedEnum;
use Radiergummi\OpenApi\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Attributes\QueryParam;

/**
 * Declares one `spatie/laravel-query-builder` allowed filter — emitted as a `filter[name]`
 * query-string parameter. Repeatable and method-level.
 *
 * Mirrors {@see QueryParam}'s JSON-Schema surface (sans `required`/`deprecated` — filter
 * parameters are always optional and the deprecation marker lives on the operation, not the
 * filter).
 *
 * ```php
 * #[AllowedFilter('status', type: 'string', enum: ['draft', 'published'])]
 * #[AllowedFilter('created_after', type: 'string', format: 'date', nullable: true)]
 * #[AllowedFilter('limit', type: 'integer', default: 25, maximum: 100)]
 * public function index(): JsonResponse { … }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class AllowedFilter extends FieldAttribute
{
    /**
     * @param non-empty-string                 $name        The filter key — becomes `filter[name]`.
     * @param null|non-empty-string            $title
     * @param null|non-empty-string            $description
     * @param null|OpenApiPrimitiveType        $type
     * @param null|non-empty-string            $format
     * @param null|list<BackedEnum|int|string> $enum        Allowed values; renders as a dropdown.
     * @param null|int<0, max>                 $minLength
     * @param null|int<0, max>                 $maxLength
     * @param null|non-empty-string            $pattern
     * @param null|int<0, max>                 $minItems
     * @param null|int<0, max>                 $maxItems
     */
    public function __construct(
        public string $name,
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
