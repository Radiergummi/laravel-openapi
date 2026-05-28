<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Radiergummi\OpenApi\Attributes\ResponseResource;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionType;

use function class_exists;
use function is_a;

/**
 * Resolves the API Resource an action returns. Used by both
 * {@see ResourceResponseResolver} (to build the response) and the ApiResources
 * lint rules (to flag undeclared/ambiguous resources) so resolution is defined
 * exactly once.
 */
final readonly class ResourceClassLocator
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
            // Collection return type with no #[ResponseResource]: the item
            // class is not recoverable from the signature — ambiguous.
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
}
