<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Contracts\Registry\Plugin;
use Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules\ResourceFieldsUndeclared;
use Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules\ResourceFieldTypeMissing;
use Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules\ResourceResponseAmbiguous;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;

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
        $registry->addRule(ResourceFieldsUndeclared::class);
        $registry->addRule(ResourceFieldTypeMissing::class);
        $registry->addRule(ResourceResponseAmbiguous::class);

        // Register JsonResource as a payload class so SuppressionCollector recognizes subclasses
        $registry->addPayloadClass(JsonResource::class);
    }
}
