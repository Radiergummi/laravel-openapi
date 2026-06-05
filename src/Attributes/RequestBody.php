<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;
use Radiergummi\OpenApi\Enums\MediaType;

/**
 * Overrides the auto-derived request body — useful for opaque webhook payloads, multipart
 * uploads, or marking a required body optional. Null properties fall through to the auto-derived
 * value (attached `Data` schema, `application/json`, `required: true`).
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class RequestBody
{
    /**
     * @param null|non-empty-string $description
     * @param null|non-empty-string $discriminator Discriminator property name; switches the body
     *                                             to a `oneOf` of `#[RequestVariant]` branches.
     */
    public function __construct(
        public ?string $description = null,
        public ?bool $required = null,
        public ?MediaType $mediaType = null,
        public ?string $discriminator = null,
    ) {}
}
