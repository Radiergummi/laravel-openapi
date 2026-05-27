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
 * Marks an operation or schema property as deprecated — emits `deprecated: true`. Exists
 * alongside PHP 8.4 native `#[\Deprecated]` and the PHPDoc `at-deprecated` tag because PHP's
 * native attribute isn't accepted on properties/parameters. `$reason` is informational only.
 */
#[Attribute(
    Attribute::TARGET_METHOD
    | Attribute::TARGET_FUNCTION
    | Attribute::TARGET_PROPERTY
    | Attribute::TARGET_PARAMETER
    | Attribute::TARGET_CLASS_CONSTANT,
)]
final readonly class Deprecated
{
    /**
     * @param null|non-empty-string $reason
     */
    public function __construct(
        public ?string $reason = null,
    ) {}
}
