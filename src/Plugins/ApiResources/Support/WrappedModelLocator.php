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
    private array $valueObjectCache = [];

    public function __construct(
        private readonly DocBlockParser $docBlockParser,
        private readonly TypeNodeResolver $typeNodeResolver,
    ) {}

    /**
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

        return $this->cache[$resourceClass] = $this->modelClassOf($this->resolveClassName($resourceClass));
    }

    /**
     * The wrapped class when it is a non-`Model` value object, for typing its public properties.
     * Returns null when the wrapped class is a `Model` (that is {@see locate}'s job) or absent.
     *
     * @param class-string<JsonResource> $resourceClass
     *
     * @return null|class-string
     *
     * @throws ReflectionException
     */
    public function locateValueObject(string $resourceClass): ?string
    {
        if (array_key_exists($resourceClass, $this->valueObjectCache)) {
            return $this->valueObjectCache[$resourceClass];
        }

        return $this->valueObjectCache[$resourceClass] = $this->valueObjectClassOf(
            $this->resolveClassName($resourceClass),
        );
    }

    /**
     * The wrapped class-string from `@mixin` (preferred) or the `@extends` generic argument,
     * unfiltered by what kind of class it is.
     *
     * @param class-string<JsonResource> $resourceClass
     *
     * @return null|class-string
     *
     * @throws ReflectionException
     */
    private function resolveClassName(string $resourceClass): ?string
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

            $className = $this->typeNodeResolver->resolveClassName($tag->type, $reflection);

            if ($className !== null && class_exists($className)) {
                return $className;
            }
        }

        foreach ($parsed->tagValues('@extends') as $tag) {
            if (!$tag instanceof ExtendsTagValueNode) {
                continue;
            }

            $className = $this->typeNodeResolver->genericValueClass($tag->type, $reflection);

            if ($className !== null && class_exists($className)) {
                return $className;
            }
        }

        return null;
    }

    /**
     * @param null|class-string $className
     *
     * @return null|class-string<Model>
     */
    private function modelClassOf(?string $className): ?string
    {
        if ($className === null || !is_a($className, Model::class, allow_string: true)) {
            return null;
        }

        /** @var class-string<Model> $className */
        return $className;
    }

    /**
     * @param null|class-string $className
     *
     * @return null|class-string
     */
    private function valueObjectClassOf(?string $className): ?string
    {
        if ($className === null || is_a($className, Model::class, allow_string: true)) {
            return null;
        }

        return $className;
    }
}
