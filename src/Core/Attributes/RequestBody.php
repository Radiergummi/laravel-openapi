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
use Radiergummi\OpenApi\Core\Enums\MediaType;

/**
 * Overrides the auto-derived request body — useful for opaque webhook payloads, multipart
 * uploads, or marking a required body optional. Null properties fall through to the auto-derived
 * value (attached `Data` schema, `application/json`, `required: true`).
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class RequestBody
{
    public function __construct(
        public ?string $description = null,
        public ?bool $required = null,
        public ?MediaType $mediaType = null,
    ) {}
}
