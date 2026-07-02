<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Attributes;

use Illuminate\Container\Attributes\Scoped;
use Radiergummi\OpenApi\Attributes\QueryParam;
use ReflectionClass;
use ReflectionFunctionAbstract;

/**
 * The single reader for `#[QueryParam]` attributes declared on a controller class and/or action.
 * Consolidates the previously duplicated `getAttributes(QueryParam::class)` loops so every consumer
 * (the query-parameter resolver, the operation builder, the duplicate-name lint rule) reads the
 * attribute the same way.
 *
 * @internal
 */
#[Scoped]
final class QueryParamReader
{
    public static function create(): self
    {
        return new self();
    }

    /**
     * Every `#[QueryParam]` instance declared on the given reflectors, class before method, in
     * source order with duplicates preserved (the duplicate-name lint rule counts them).
     *
     * @param null|ReflectionClass<object> $controller
     *
     * @return list<QueryParam>
     */
    public function read(?ReflectionClass $controller, ?ReflectionFunctionAbstract $action): array
    {
        $instances = [];

        foreach ([$controller, $action] as $reflector) {
            foreach ($reflector?->getAttributes(QueryParam::class) ?? [] as $attribute) {
                $instances[] = $attribute->newInstance();
            }
        }

        return $instances;
    }

    /**
     * `#[QueryParam]` instances keyed by name, class before method so a method-level attribute
     * shadows a class-level one on a name collision (last write wins).
     *
     * @param null|ReflectionClass<object> $controller
     *
     * @return array<string, QueryParam>
     */
    public function mergedByName(
        ?ReflectionClass $controller,
        ?ReflectionFunctionAbstract $action,
    ): array {
        $merged = [];

        foreach ($this->read($controller, $action) as $instance) {
            $merged[$instance->name] = $instance;
        }

        return $merged;
    }
}
