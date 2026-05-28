<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Registry;

use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;

/**
 * The result of a schema resolver: the component schema has been registered in the
 * {@see ComponentSchemaRegistry} under `componentKey`, and should be referenced with the given
 * media type.
 */
final readonly class ResolvedSchema
{
    public function __construct(
        public string $componentKey,
        public MediaType $mediaType = MediaType::Json,
    ) {}
}
