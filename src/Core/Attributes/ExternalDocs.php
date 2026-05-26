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
 * Attaches an external documentation link to an operation — renders as a "Learn more" link in
 * Scalar/Swagger UI. One entry per operation; method-level wins over class-level.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class ExternalDocs
{
    public function __construct(
        public string $url,
        public ?string $description = null,
    ) {}
}
