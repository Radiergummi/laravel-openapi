<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

/**
 * The partial schema fields a migration column declaration contributes to a model property.
 *
 * Every field is optional: a migration call fills only the signals its column type implies, and
 * {@see EloquentModelToSchema} writes each into the property only where the cast / `@property`
 * tag / attribute left it undefined.
 *
 * @internal
 */
final readonly class ColumnMetadata
{
    /**
     * @param null|string                $type        a coarse type hint, only where the migration implies
     *                                                one the cast wouldn't (`object` for json, `number`
     *                                                for decimal, `integer` for year/unsigned)
     * @param null|string                $format      an OpenAPI string format (uuid, ip, date, date-time)
     * @param null|string                $pattern     a regex pattern (mac address has no format)
     * @param null|int                   $maxLength   from `string($n)` / `char($n)`
     * @param null|int                   $minimum     0 for unsigned/auto-increment columns
     * @param null|float|int             $multipleOf  from a decimal column's scale
     * @param null|list<string>          $enum        from `enum([...])` / `set([...])`
     * @param bool                       $nullable    whether `->nullable()` was applied
     * @param null|bool|float|int|string $default     a literal `->default(...)` value
     * @param null|string                $description from `->comment('...')`
     */
    public function __construct(
        public ?string $type = null,
        public ?string $format = null,
        public ?string $pattern = null,
        public ?int $maxLength = null,
        public ?int $minimum = null,
        public int|float|null $multipleOf = null,
        public ?array $enum = null,
        public bool $nullable = false,
        public string|int|float|bool|null $default = null,
        public bool $hasDefault = false,
        public ?string $description = null,
    ) {}
}
