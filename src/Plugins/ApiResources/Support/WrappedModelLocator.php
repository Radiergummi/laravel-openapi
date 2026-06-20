<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Support;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use PHPStan\PhpDocParser\Ast\PhpDoc\ExtendsTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\MixinTagValueNode;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use ReflectionClass;
use ReflectionException;

use function array_key_exists;
use function class_exists;
use function is_a;

/**
 * Resolves the Eloquent model a `JsonResource` wraps from its class docblock.
 *
 * Checks `@mixin` first, then the generic type argument of `@extends`. Shared by the schema
 * builder and the `resource.fields-undeclared` lint rule.
 *
 * @internal
 */
#[Scoped]
final class WrappedModelLocator
{
    /**
     * @var array<class-string<JsonResource>, null|class-string<Model>>
     */
    private array $cache = [];

    /**
     * @var array<class-string<JsonResource>, null|class-string>
     */
    private array $wrappedCache = [];

    public function __construct(
        private readonly DocBlockParser $docBlockParser,
        private readonly TypeNodeResolver $typeNodeResolver,
    ) {}

    /**
     * The Eloquent model the resource wraps, from its `@mixin` / `@extends` docblock.
     *
     * @param class-string<JsonResource> $resourceClass
     *
     * @return null|class-string<Model>
     *
     * @throws ReflectionException
     */
    public function locate(string $resourceClass): ?string
    {
        if (array_key_exists($resourceClass, $this->cache)) {
            return $this->cache[$resourceClass];
        }

        return $this->cache[$resourceClass] = $this->modelClassOf($this->resolveWrapped($resourceClass));
    }

    /**
     * The class the resource wraps, model or not, from its `@mixin` / `@extends` docblock.
     * Used to type fields read off a wrapped non-Model value object.
     *
     * @param class-string<JsonResource> $resourceClass
     *
     * @return null|class-string
     *
     * @throws ReflectionException
     */
    public function locateWrapped(string $resourceClass): ?string
    {
        if (array_key_exists($resourceClass, $this->wrappedCache)) {
            return $this->wrappedCache[$resourceClass];
        }

        return $this->wrappedCache[$resourceClass] = $this->resolveWrapped($resourceClass);
    }

    /**
     * @param class-string<JsonResource> $resourceClass
     *
     * @return null|class-string
     *
     * @throws ReflectionException
     */
    private function resolveWrapped(string $resourceClass): ?string
    {
        $reflection = new ReflectionClass($resourceClass);
        $docComment = $reflection->getDocComment();

        if ($docComment === false) {
            return null;
        }

        $parsed = $this->docBlockParser->parse($docComment);

        foreach ($parsed->tagValues('@mixin') as $tag) {
            if (!$tag instanceof MixinTagValueNode) {
                continue;
            }

            $className = $this->existingClass($this->typeNodeResolver->resolveClassName($tag->type, $reflection));

            if ($className !== null) {
                return $className;
            }
        }

        foreach ($parsed->tagValues('@extends') as $tag) {
            if (!$tag instanceof ExtendsTagValueNode) {
                continue;
            }

            $className = $this->existingClass($this->typeNodeResolver->genericValueClass($tag->type, $reflection));

            if ($className !== null) {
                return $className;
            }
        }

        return null;
    }

    /**
     * @return null|class-string
     */
    private function existingClass(?string $className): ?string
    {
        return $className !== null && class_exists($className) ? $className : null;
    }

    /**
     * @return null|class-string<Model>
     */
    private function modelClassOf(?string $className): ?string
    {
        if ($className === null || !class_exists($className)) {
            return null;
        }

        if (!is_a($className, Model::class, allow_string: true)) {
            return null;
        }

        /** @var class-string<Model> $className */
        return $className;
    }
}
