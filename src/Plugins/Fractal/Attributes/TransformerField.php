<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Attributes;

use Attribute;
use BackedEnum;
use Radiergummi\OpenApi\Core\Attributes\FieldAttribute;

/**
 * Declares one output key of a Fractal transformer.
 *
 * Repeatable and class-level: a transformer's `transform()` return array is not
 * a set of typed class properties, so each key is declared with its own
 * attribute on the transformer class.
 *
 * When `type` is a class-string the field is emitted as a `$ref`; otherwise it
 * is a JSON-Schema scalar type.
 *
 * ```php
 * #[TransformerField('id', type: 'integer')]
 * #[TransformerField('author', type: AuthorTransformer::class)]
 * final class BookTransformer extends TransformerAbstract { … }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class TransformerField extends FieldAttribute
{
    /**
     * @param string                           $name        The output key.
     * @param null|class-string|string         $type        A JSON-Schema scalar type, or a class-string for a nested
     *                                                      `$ref`.
     * @param bool                             $conditional When true, the key is kept in `properties` but omitted from
     *                                                      `required`.
     * @param null|list<BackedEnum|int|string> $enum
     */
    public function __construct(
        public string $name,
        ?string $title = null,
        ?string $description = null,
        mixed $example = null,
        ?string $type = null,
        ?string $format = null,
        ?bool $nullable = null,
        ?array $enum = null,
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
