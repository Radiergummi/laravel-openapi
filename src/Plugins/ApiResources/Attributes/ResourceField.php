<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Attributes;

use Attribute;
use BackedEnum;
use Radiergummi\OpenApi\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Support\Attributes\FieldDefault;

/**
 * Declares one output key of an Eloquent API Resource.
 *
 * Repeatable and class-level: a `JsonResource`'s keys are arbitrary `toArray()`
 * entries, not typed class properties, so each key is declared with its own
 * attribute on the resource class.
 *
 * When `type` is a class-string the field is emitted as a `$ref` to that
 * class's schema (resolved through the registered ref-schema resolvers);
 * otherwise `type` is a JSON-Schema scalar type (`string`, `integer`, …).
 *
 * ```php
 * #[ResourceField('id', type: 'integer')]
 * #[ResourceField('owner', type: CompanyResource::class)]
 * final class ProjectResource extends JsonResource { … }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class ResourceField extends FieldAttribute
{
    /**
     * @param non-empty-string                                                             $name        The output key.
     * @param null|non-empty-string                                                        $title
     * @param null|non-empty-string                                                        $description
     * @param null|class-string|OpenApiPrimitiveType                                       $type        JSON-Schema scalar
     *                                                                                                  type or class-string
     *                                                                                                  for a `$ref`.
     * @param null|non-empty-string                                                        $format
     * @param null|array<int, BackedEnum|int|string>|class-string<BackedEnum>|FieldDefault $enum
     * @param null|int<0, max>                                                             $minLength
     * @param null|int<0, max>                                                             $maxLength
     * @param null|non-empty-string                                                        $pattern
     * @param null|int<0, max>                                                             $minItems
     * @param null|int<0, max>                                                             $maxItems
     * @param bool                                                                         $conditional Keeps the key in
     *                                                                                                  `properties` but
     *                                                                                                  out of `required`
     *                                                                                                  (for `when()`/
     *                                                                                                  `whenLoaded()`).
     */
    public function __construct(
        public string $name,
        ?string $title = null,
        ?string $description = null,
        mixed $example = FieldDefault::Unset,
        ?string $type = null,
        ?string $format = null,
        ?string $items = null,
        ?bool $nullable = null,
        array|string|FieldDefault|null $enum = FieldDefault::Unset,
        int|float|null $minimum = null,
        int|float|null $maximum = null,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $pattern = null,
        ?int $minItems = null,
        ?int $maxItems = null,
        ?bool $uniqueItems = null,
        bool $conditional = false,
    ) {
        parent::__construct(
            title: $title,
            description: $description,
            example: $example,
            type: $type,
            format: $format,
            items: $items,
            nullable: $nullable,
            enum: $enum,
            minimum: $minimum,
            maximum: $maximum,
            minLength: $minLength,
            maxLength: $maxLength,
            pattern: $pattern,
            minItems: $minItems,
            maxItems: $maxItems,
            uniqueItems: $uniqueItems,
            conditional: $conditional,
        );
    }
}
