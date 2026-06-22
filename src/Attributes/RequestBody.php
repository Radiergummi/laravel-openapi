<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;
use Radiergummi\OpenApi\Enums\MediaType;

/**
 * Overrides the auto-derived request body. Useful for opaque webhook payloads, multipart
 * uploads, or marking a required body optional. Null properties fall through to the auto-derived
 * value (attached `Data` schema, `application/json`, `required: true`).
 *
 * Pass `mediaTypes` to offer the same body under several content types (e.g.
 * `application/json` + `application/yaml`); the generator emits one entry per declared type
 * carrying the same schema. `mediaTypes` wins over the single `mediaType` when both are set.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class RequestBody
{
    /**
     * @param null|non-empty-string $description
     * @param null|non-empty-string $discriminator Discriminator property name; switches the body
     *                                             to a `oneOf` of `#[RequestVariant]` branches.
     * @param null|list<MediaType>  $mediaTypes    Declares the body under multiple content types;
     *                                             wins over `mediaType` when both are set.
     */
    public function __construct(
        public ?string $description = null,
        public ?bool $required = null,
        public ?MediaType $mediaType = null,
        public ?string $discriminator = null,
        public ?array $mediaTypes = null,
    ) {}
}
