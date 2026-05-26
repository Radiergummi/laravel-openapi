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
 * Sets the human-readable summary of an operation (controller method or class) or a schema's
 * `title` (Spatie Data, JsonResource). Method docblock outranks class-level attributes;
 * method `#[Summary]` outranks the method docblock and `#[Operation(summary:)]`.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Summary
{
    public function __construct(public string $value) {}
}
