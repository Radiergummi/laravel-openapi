<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources;

use Illuminate\Http\Resources\Attributes\Collects;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Radiergummi\OpenApi\Attributes\ResponseResource;
use Radiergummi\OpenApi\Contracts\Routing\ResourceTargetLocator;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Routing\ResourceTarget;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionType;

use function class_exists;
use function is_a;
use function is_string;

/**
 * Resolves the API Resource an action returns. Used by both
 * {@see ResourceResponseResolver} (to build the response) and the ApiResources
 * lint rules (to flag undeclared/ambiguous resources) so resolution is defined
 * exactly once.
 */
final readonly class ResourceClassLocator implements ResourceTargetLocator
{
    public function locate(ActionDescriptor $descriptor): ?ResourceTarget
    {
        $reflector = $descriptor->actionReflector;

        if ($reflector === null) {
            return null;
        }

        $returnType = $reflector->getReturnType();
        $returnsCollection = $this->isCollectionType($returnType);

        $attribute = $this->readResponseResource($reflector, $descriptor);

        if (
            $attribute !== null
            && class_exists($attribute->class)
            && is_a($attribute->class, JsonResource::class, allow_string: true)
        ) {
            return new ResourceTarget(
                resourceClass: $attribute->class,
                isCollection: $attribute->collection ?? $returnsCollection,
            );
        }

        if (!$returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return null;
        }

        $name = $returnType->getName();

        if (!is_a($name, JsonResource::class, allow_string: true)) {
            return null;
        }

        if ($returnsCollection) {
            /** @var class-string<ResourceCollection> $name */
            $itemClass = $this->readCollectsAttribute($name) ?? $this->readCollectsProperty($name);

            if ($itemClass !== null) {
                return new ResourceTarget(
                    resourceClass: $itemClass,
                    isCollection: true,
                );
            }

            // Collection return type with no #[ResponseResource], #[Collects], or
            // $collects: the item class is not recoverable from the signature — ambiguous.
            return new ResourceTarget(resourceClass: null, isCollection: true);
        }

        /** @var class-string<JsonResource> $name */
        return new ResourceTarget(resourceClass: $name, isCollection: false);
    }

    private function isCollectionType(?ReflectionType $returnType): bool
    {
        return $returnType instanceof ReflectionNamedType
            && !$returnType->isBuiltin()
            && is_a($returnType->getName(), ResourceCollection::class, allow_string: true);
    }

    private function readResponseResource(
        ReflectionFunctionAbstract $reflector,
        ActionDescriptor $descriptor,
    ): ?ResponseResource {
        $source = $reflector->getAttributes(ResponseResource::class)[0] ?? null;

        if ($source === null && $descriptor->controller !== null) {
            $source = $descriptor->controller->getAttributes(ResponseResource::class)[0] ?? null;
        }

        return $source?->newInstance();
    }

    /**
     * @param class-string<ResourceCollection> $collectionClass
     *
     * @return null|class-string<JsonResource>
     */
    private function readCollectsAttribute(string $collectionClass): ?string
    {
        if (!class_exists(Collects::class)) {
            return null;
        }

        $reflection = new ReflectionClass($collectionClass);
        $attribute = $reflection->getAttributes(Collects::class)[0] ?? null;

        if ($attribute === null) {
            return null;
        }

        /** @var Collects $instance */
        $instance = $attribute->newInstance();
        $candidate = $instance->class;

        if (!class_exists($candidate) || !is_a($candidate, JsonResource::class, allow_string: true)) {
            return null;
        }

        /** @var class-string<JsonResource> $candidate */
        return $candidate;
    }

    /**
     * @param class-string<ResourceCollection> $collectionClass
     *
     * @return null|class-string<JsonResource>
     */
    private function readCollectsProperty(string $collectionClass): ?string
    {
        $reflection = new ReflectionClass($collectionClass);
        $defaults = $reflection->getDefaultProperties();
        $candidate = $defaults['collects'] ?? null;

        if (!is_string($candidate) || !class_exists($candidate)) {
            return null;
        }

        if (!is_a($candidate, JsonResource::class, allow_string: true)) {
            return null;
        }

        /** @var class-string<JsonResource> $candidate */
        return $candidate;
    }
}
