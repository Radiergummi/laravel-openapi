<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\SchemaFromResource;
use ReflectionException;

use function is_a;

/**
 * Resolves an Eloquent API Resource class to a `#/components/schemas/…` ref,
 * registering its component schema via {@see SchemaFromResource} on first use.
 */
#[Scoped]
final readonly class ResourceRefSchemaResolver implements RefSchemaResolver
{
    public function __construct(
        private SchemaFromResource $schemaFromResource,
    ) {}

    /**
     * @throws ReflectionException
     */
    public function resolveRef(string $class): ?string
    {
        if (!$this->canResolve($class)) {
            return null;
        }

        /** @var class-string<JsonResource> $class */
        return $this->schemaFromResource->buildRef($class);
    }

    public function canResolve(string $class): bool
    {
        return is_a($class, JsonResource::class, allow_string: true);
    }
}
