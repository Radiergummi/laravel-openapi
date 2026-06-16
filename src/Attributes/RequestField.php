<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;
use BackedEnum;
use Radiergummi\OpenApi\Support\Attributes\FieldDefault;

/**
 * Documents a request-body input field.
 *
 * Place on a Spatie Data class property / promoted constructor parameter, or on a FormRequest
 * `PARAM_*` class constant — there the field name is taken from the target. Or stack it
 * (repeatable) on a controller action to document a request body field-by-field when the action
 * validates outside a FormRequest/Data class (e.g. in an Action/service); there `$name` is
 * required and composes with `#[RequestBody]` for the envelope. Request fields support `writeOnly`
 * but not `readOnly` (a request field is never read-only).
 *
 * ```php
 * #[RequestBody(description: 'Create a site.')]
 * #[RequestField('domain', required: true, type: 'string', format: 'hostname')]
 * #[RequestField('php_version', type: 'string', default: '8.4')]
 * public function store(Request $request) { … }
 * ```
 */
#[Attribute(
    Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER | Attribute::TARGET_CLASS_CONSTANT
    | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE,
)]
final readonly class RequestField extends FieldAttribute
{
    /**
     * @param null|non-empty-string                                                        $name                 Field
     *                                                                                                           name;
     *                                                                                                           required
     *                                                                                                           on a
     *                                                                                                           method,
     *                                                                                                           derived
     *                                                                                                           from
     *                                                                                                           the
     *                                                                                                           target
     *                                                                                                           otherwise
     * @param null|non-empty-string                                                        $title
     * @param null|non-empty-string                                                        $description
     * @param null|class-string|OpenApiPrimitiveType                                       $type                 A
     *                                                                                                           JSON-Schema
     *                                                                                                           scalar
     *                                                                                                           type,
     *                                                                                                           or a
     *                                                                                                           class-string
     *                                                                                                           for a
     *                                                                                                           nested
     *                                                                                                           `$ref`.
     * @param null|non-empty-string                                                        $format
     * @param null|array<int, BackedEnum|int|string>|class-string<BackedEnum>|FieldDefault $enum
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
        public ?string $name = null,
        public ?bool $required = null,
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
        ?bool $writeOnly = null,
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
            writeOnly: $writeOnly,
            x: $x,
            additionalProperties: $additionalProperties,
        );
    }
}
