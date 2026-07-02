<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Support;

use Illuminate\Http\Resources\Attributes\Collects;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Override;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Attributes\ResponseResource;
use Radiergummi\OpenApi\Contracts\Routing\ResourceTargetLocator;
use Radiergummi\OpenApi\Plugins\ApiResources\Resolvers\ResourceResponseResolver;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Routing\ResourceTarget;
use Radiergummi\OpenApi\Support\Routing\GenericContainerReturnType;
use Radiergummi\OpenApi\Support\Routing\LooseResponseReturnType;
use ReflectionClass;
use ReflectionException;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionType;

use function class_exists;
use function is_a;
use function is_string;

/**
 * Resolves the API Resource class an action returns. Used by both
 * {@see ResourceResponseResolver} and the ApiResources lint rules so resolution is defined once.
 *
 * Resolution order: `#[ResponseResource]`, then concrete return type (or `#[Collects]` /
 * `$collects`). When the signature names a base resource type, declares no resource return type at
 * all (absent/builtin), or names a generic container (`Collection`, Eloquent `Collection`,
 * `LazyCollection`), {@see ReturnExpressionResourceReader} inspects the return expression; refusal
 * leaves the endpoint ambiguous. On the untyped and container paths the refusal notice is
 * suppressed, since the reader then runs on every such action and most are not resources.
 */
final readonly class ResourceClassLocator implements ResourceTargetLocator
{
    public function __construct(
        private ReturnExpressionResourceReader $returnExpressionReader,
    ) {}

    public static function create(?LoggerInterface $logger = null): self
    {
        return new self(ReturnExpressionResourceReader::create($logger));
    }

    /**
     * @throws ReflectionException
     */
    #[Override]
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
            // Untyped actions (no resource return type) still commonly return a resource by
            // convention. Consult the body scan; a non-resource shape reads back as null and
            // keeps today's no-content behaviour, silently to avoid a notice per non-resource.
            return $this->locateFromReturnExpression($descriptor, silent: true);
        }

        $name = $returnType->getName();

        if (GenericContainerReturnType::matches($returnType)) {
            // A generic container (`Collection`, Eloquent `Collection`, `LazyCollection`) carries
            // no item type, so the body's resource factory is the only evidence. Silent, since the
            // scan now runs on every container-returning action and most are not resources.
            return $this->locateFromReturnExpression($descriptor, silent: true);
        }

        if (!is_a($name, JsonResource::class, allow_string: true)) {
            // A loose response wrapper (`JsonResponse`, `Response`, and their Symfony parents) is too
            // generic to carry a resource type in its signature, but the action commonly returns a
            // resource by convention (framework-coerced). Consult the body scan before giving up;
            // a non-resource shape reads back null and keeps today's no-content behaviour. Silent,
            // like the untyped/container paths, since most such actions are not resources.
            if (LooseResponseReturnType::matches($returnType)) {
                return $this->locateFromReturnExpression($descriptor, silent: true);
            }

            return null;
        }

        if ($returnsCollection) {
            /** @var class-string<ResourceCollection> $name */
            $itemClass = $this->readCollectsAttribute($name)
                ?? $this->readCollectsProperty($name);

            if ($itemClass !== null) {
                return new ResourceTarget(
                    resourceClass: $itemClass,
                    isCollection: true,
                );
            }

            // No #[ResponseResource], #[Collects], or $collects: fall back to the return
            // expression before reporting the endpoint as ambiguous.
            return $this->locateFromReturnExpression($descriptor)
                ?? new ResourceTarget(resourceClass: null, isCollection: true);
        }

        if ($name === JsonResource::class) {
            // Base class has no shape; try the return expression to get a concrete resource.
            $bodyTarget = $this->locateFromReturnExpression($descriptor);

            if ($bodyTarget !== null) {
                return $bodyTarget;
            }
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

        /** @var null|ResponseResource */
        return $source?->newInstance();
    }

    /**
     * @param class-string<ResourceCollection> $collectionClass
     *
     * @return null|class-string<JsonResource>
     *
     * @throws ReflectionException
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
     *
     * @throws ReflectionException
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

    /**
     * @throws ReflectionException
     */
    private function locateFromReturnExpression(
        ActionDescriptor $descriptor,
        bool $silent = false,
    ): ?ResourceTarget {
        if ($descriptor->method === null) {
            return null;
        }

        return $this->returnExpressionReader->read($descriptor->method, silent: $silent);
    }
}
