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
use Illuminate\Http\Resources\Json\ResourceCollection;
use Radiergummi\OpenApi\Core\Attributes\ResponseResource;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use ReflectionFunctionAbstract;
use ReflectionNamedType;

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

        $returnsCollection = $this->returnTypeIsCollection($reflector);

        $attribute = $this->readResponseResource($descriptor);

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

        $returnType = $reflector->getReturnType();

        if (!$returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return null;
        }

        $name = $returnType->getName();

        if (!is_a($name, JsonResource::class, allow_string: true)) {
            return null;
        }

        if (is_a($name, ResourceCollection::class, allow_string: true)) {
            // Collection return type with no #[ResponseResource]: the item
            // class is not recoverable from the signature — ambiguous.
            return new ResourceTarget(resourceClass: null, isCollection: true);
        }

        /** @var class-string<JsonResource> $name */
        return new ResourceTarget(resourceClass: $name, isCollection: false);
    }

    private function readResponseResource(ActionDescriptor $descriptor): ?ResponseResource
    {
        $reflector = $descriptor->actionReflector;

        $source = $reflector?->getAttributes(ResponseResource::class)[0] ?? null;

        if ($source === null && $descriptor->controller !== null) {
            $source = $descriptor->controller->getAttributes(ResponseResource::class)[0] ?? null;
        }

        return $source?->newInstance();
    }

    private function returnTypeIsCollection(ReflectionFunctionAbstract $reflector): bool
    {
        $returnType = $reflector->getReturnType();

        return $returnType instanceof ReflectionNamedType
            && !$returnType->isBuiltin()
            && is_a($returnType->getName(), ResourceCollection::class, allow_string: true);
    }
}
