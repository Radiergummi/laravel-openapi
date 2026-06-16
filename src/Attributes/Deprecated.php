<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;

/**
 * Marks an operation or schema property as deprecated, emitting `deprecated: true`. Exists
 * alongside PHP 8.4 native `#[\Deprecated]` and the PHPDoc `at-deprecated` tag because PHP's
 * native attribute isn't accepted on properties/parameters. `$reason` is informational only.
 */
#[Attribute(
    Attribute::TARGET_METHOD
    | Attribute::TARGET_FUNCTION
    | Attribute::TARGET_PROPERTY
    | Attribute::TARGET_PARAMETER,
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
