<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Registry;

use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;

/**
 * Returned by a schema resolver: the schema is registered in {@see ComponentSchemaRegistry}
 * under `componentKey` and should be referenced with the given media type.
 */
final readonly class ResolvedSchema
{
    public function __construct(
        public string $componentKey,
        public MediaType $mediaType = MediaType::Json,
    ) {}
}
