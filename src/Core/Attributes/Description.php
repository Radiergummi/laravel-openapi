<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;

/**
 * Sets the long-form description of an operation (controller method or class) or a schema
 * (Spatie Data, JsonResource). Method docblock outranks class-level attributes; method
 * `#[Description]` outranks the method docblock and `#[Operation(description:)]`.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Description
{
    /**
     * @param non-empty-string $value
     */
    public function __construct(public string $value) {}
}
