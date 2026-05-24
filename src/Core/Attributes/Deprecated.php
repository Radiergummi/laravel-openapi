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
 * Marks an operation or schema property as deprecated.
 *
 * The generator emits `deprecated: true` on the affected operation (when placed on a controller
 * method or function) or schema property (when placed on a property, promoted constructor
 * parameter, or class constant).
 *
 * This attribute is symmetric to the PHPDoc at-deprecated tag (used on Spatie Data properties)
 * and the PHP 8.4 native `#[\Deprecated]` attribute (used on controller methods). It exists so
 * authors have a single, consistent attribute-based path that works on every target the package
 * cares about — including properties and parameters, where PHP's native attribute is not
 * accepted.
 *
 * The optional `$reason` is informational metadata; the package does not currently fold it into
 * the generated `description` (the PHPDoc and native paths don't either), but consumers may
 * read it via reflection in custom resolvers.
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
    public function __construct(
        public ?string $reason = null,
    ) {}
}
