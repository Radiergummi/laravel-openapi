<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources;

use Radiergummi\OpenApi\Core\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Core\Registry\Plugin;

/**
 * Teaches the OpenAPI core to document Eloquent API Resources
 * (`JsonResource` / `ResourceCollection` subclasses) as response schemas.
 */
final class ApiResourcesPlugin implements Plugin
{
    public function register(OpenApiRegistry $registry): void
    {
        $registry->addRefSchemaResolver(ResourceRefSchemaResolver::class);
        $registry->addPrimaryResponseResolver(ResourceResponseResolver::class);
        // The three lint rules are added in Task 13, once their classes exist.
    }
}
