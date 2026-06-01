<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;

/**
 * Sets the human-readable summary of an operation (controller method or class) or a schema's
 * `title` (Spatie Data, JsonResource). Method docblock outranks class-level attributes;
 * method `#[Summary]` outranks the method docblock and `#[Operation(summary:)]`.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Summary
{
    /**
     * @param non-empty-string $value
     */
    public function __construct(public string $value) {}
}
