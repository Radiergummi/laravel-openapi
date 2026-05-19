<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Core\Registry\RefSchemaResolver;

use function is_a;

/**
 * Resolves an Eloquent API Resource class to a `#/components/schemas/…` ref,
 * registering its component schema via {@see SchemaFromResource} on first use.
 */
final readonly class ResourceRefSchemaResolver implements RefSchemaResolver
{
    public function __construct(
        private SchemaFromResource $schemaFromResource,
    ) {}

    public function resolveRef(string $class): ?string
    {
        if (!is_a($class, JsonResource::class, allow_string: true)) {
            return null;
        }

        /** @var class-string<JsonResource> $class */
        return '#/components/schemas/' . $this->schemaFromResource->build($class);
    }
}
