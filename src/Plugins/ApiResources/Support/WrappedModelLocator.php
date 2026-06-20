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

        /** @var null|class-string<Model> $modelClass */
        $modelClass = $this->resolveClassName(
            $resourceClass,
            static fn(string $className): bool => is_a($className, Model::class, allow_string: true),
        );

        return $this->cache[$resourceClass] = $modelClass;
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

        return $this->valueObjectCache[$resourceClass] = $this->resolveClassName(
            $resourceClass,
            static fn(string $className): bool => !is_a($className, Model::class, allow_string: true),
        );
    }

    /**
     * The first wrapped class accepted by `$matches`, taken from `@mixin` (preferred) then the
     * `@extends` generic argument. Filtering inside both loops preserves the tag precedence even
     * when an earlier tag names a class of the other kind (e.g. a non-Model `@mixin` alongside a
     * Model `@extends`).
     *
     * @param class-string<JsonResource>   $resourceClass
     * @param callable(class-string): bool $matches
     *
     * @return null|class-string
     *
     * @throws ReflectionException
     */
    private function resolveClassName(string $resourceClass, callable $matches): ?string
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

            if ($className !== null && class_exists($className) && $matches($className)) {
                return $className;
            }
        }

        foreach ($parsed->tagValues('@extends') as $tag) {
            if (!$tag instanceof ExtendsTagValueNode) {
                continue;
            }

            $className = $this->typeNodeResolver->genericValueClass($tag->type, $reflection);

            if ($className !== null && class_exists($className) && $matches($className)) {
                return $className;
            }
        }

        return null;
    }
}
