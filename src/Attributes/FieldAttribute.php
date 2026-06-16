<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use BackedEnum;
use InvalidArgumentException;
use Radiergummi\OpenApi\Support\Attributes\DescriptionDirectives;
use Radiergummi\OpenApi\Support\Attributes\FieldDefault;
use Radiergummi\OpenApi\Support\Generator\SchemaDescriptor;

use function array_values;
use function is_a;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Abstract base for scope-specific field attributes ({@see PathParam}, {@see QueryParam},
 * {@see RequestField}, {@see ResponseField}). Not an `#[Attribute]` itself.
 *
 * `example` and `enum` use {@see FieldDefault::Unset} as their default so {@see descriptor()}
 * can distinguish "not passed" from "explicitly null", falling back to description directives
 * only for the former.
 */
abstract readonly class FieldAttribute
{
    /** @var null|array<int, BackedEnum|int|string>|FieldDefault */
    public array|FieldDefault|null $enum;

    /**
     * @param null|non-empty-string                                                        $title
     * @param null|non-empty-string                                                        $description
     * @param null|class-string|OpenApiPrimitiveType                                       $type
     * @param null|non-empty-string                                                        $format
     * @param null|array<int, BackedEnum|int|string>|class-string<BackedEnum>|FieldDefault $enum                 A list
     *                                                                                                           of allowed values, or a
     *                                                                                                           backed-enum class-string
     *                                                                                                           resolved to its cases.
     * @param null|int<0, max>                                                             $minLength
     * @param null|int<0, max>                                                             $maxLength
     * @param null|non-empty-string                                                        $pattern
     * @param null|int<0, max>                                                             $minItems
     * @param null|int<0, max>                                                             $maxItems
     * @param bool                                                                         $conditional          When true, the field is kept in `properties` but
     *                                                                                                           removed from `required`; for response fields
     *                                                                                                           emitted conditionally via `$this->when()` /
     *                                                                                                           `$this->whenLoaded()`.
     * @param null|array<string, mixed>                                                    $x                    Vendor extensions (`x-*`); keys must carry the
     *                                                                                                           `x-` prefix and are emitted verbatim.
     * @param null|bool|string                                                             $additionalProperties Map-value override: `true`/`false`, or a type
     *                                                                                                           string wrapped into a nested value schema.
     *                                                                                                           Wins over inferred map values.
     *
     * @throws InvalidArgumentException When `$enum` is a string that is not a backed-enum class-string.
     */
    protected function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public mixed $example = FieldDefault::Unset,
        public ?string $type = null,
        public ?string $format = null,
        public ?string $items = null,
        public ?bool $nullable = null,
        public mixed $default = null,
        array|string|FieldDefault|null $enum = FieldDefault::Unset,
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
        public ?array $x = null,
        public bool|string|null $additionalProperties = null,
    ) {
        // Normalise a backed-enum class-string to its cases for uniform downstream handling.
        if (is_string($enum)) {
            if (!is_a($enum, BackedEnum::class, true)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'enum: expects an array of values or a backed-enum class-string, got "%s".',
                        $enum,
                    ),
                );
            }

            $this->enum = $enum::cases();
        } else {
            $this->enum = $enum;
        }
    }

    /**
     * Returns the explicit `example:` value, or `null` when the author did not pass one.
     */
    public function explicitExample(): mixed
    {
        return $this->example instanceof FieldDefault ? null : $this->example;
    }

    /**
     * Returns the explicit `enum:` value, or `null` when the author did not pass one.
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

        // Explicit attribute argument wins over description directives, even when null.
        $example = match (true) {
            $this->example instanceof FieldDefault => $parsed->example,
            $this->example === null => null,
            default => $this->example,
        };
        $enum = match (true) {
            $this->enum instanceof FieldDefault => $parsed->enum,
            $this->enum === null => null,
            default => array_values($this->enum),
        };

        // Infer scalar type from the resolved enum list so `type:` need not be set explicitly.
        $type = $this->type;

        if ($type === null && isset($enum[0]) && $enum[0] instanceof BackedEnum) {
            $type = is_int($enum[0]->value) ? 'integer' : 'string';
        }

        return new SchemaDescriptor(
            title: $this->title,
            description: $parsed->cleanDescription,
            example: $example,
            type: $type,
            format: $this->format,
            items: $this->items,
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
            x: $this->x,
            additionalProperties: $this->additionalProperties,
        );
    }
}
