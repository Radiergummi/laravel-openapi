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

use function array_key_exists;
use function class_exists;
use function is_a;

/**
 * Resolves the Eloquent model a `JsonResource` wraps from the resource's class docblock —
 * an `@mixin \App\Models\User` tag first, then a generic `@extends Base<User>`; only `Model`
 * subclasses count. Shared by the schema builder (the passthrough/dynamic `toArray()` fallback
 * and `$this->field` value resolution) and the `resource.fields-undeclared` lint rule, so
 * resource→model resolution is defined exactly once (folded #98 design).
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

    public function __construct(
        private readonly DocBlockParser $docBlockParser,
        private readonly TypeNodeResolver $typeNodeResolver,
    ) {}

    /**
     * @param class-string<JsonResource> $resourceClass
     *
     * @return null|class-string<Model>
     */
    public function locate(string $resourceClass): ?string
    {
        if (array_key_exists($resourceClass, $this->cache)) {
            return $this->cache[$resourceClass];
        }

        return $this->cache[$resourceClass] = $this->resolve($resourceClass);
    }

    /**
     * @param class-string<JsonResource> $resourceClass
     *
     * @return null|class-string<Model>
     */
    private function resolve(string $resourceClass): ?string
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

            $modelClass = $this->modelClassOf(
                $this->typeNodeResolver->resolveClassName($tag->type, $reflection),
            );

            if ($modelClass !== null) {
                return $modelClass;
            }
        }

        foreach ($parsed->tagValues('@extends') as $tag) {
            if (!$tag instanceof ExtendsTagValueNode) {
                continue;
            }

            $modelClass = $this->modelClassOf(
                $this->typeNodeResolver->genericValueClass($tag->type, $reflection),
            );

            if ($modelClass !== null) {
                return $modelClass;
            }
        }

        return null;
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
