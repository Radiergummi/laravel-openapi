<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;

/**
 * Attaches an external documentation link to an operation, rendered as a "Learn more" link in
 * Scalar/Swagger UI. One entry per operation; method-level wins over class-level.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class ExternalDocs
{
    /**
     * @param non-empty-string      $url
     * @param null|non-empty-string $description
     */
    public function __construct(
        public string $url,
        public ?string $description = null,
    ) {}
}
