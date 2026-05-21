<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Routing;

use Illuminate\Routing\Route;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;

use function str_contains;

final class ActionDescriptor
{
    /**
     * The list of parameters in the action's signature that are also present as URI parameters in
     * the route's URI.
     *
     * @var list<ReflectionParameter>
     */
    public array $uriParameters {
        get => $this->resolveUriParameters();
    }

    /**
     * The reflector that carries the action's attributes and docblock: a {@see ReflectionMethod}
     * for controller actions, a {@see ReflectionFunction} for closure routes, or null when neither
     * is available.
     */
    public ?ReflectionFunctionAbstract $actionReflector {
        get => $this->method ?? $this->closure;
    }

    /**
     * Buckets of `ReflectionAttribute`s keyed by attribute FQCN. Built lazily on first access via
     * a single `getAttributes()` call per reflector — every caller that asks for a specific
     * attribute class then reads from the bucket rather than walking the reflector's attribute list
     * again. Saves the ~17 attribute walks per route that `OperationBuilder` used to do.
     *
     * @var array<int, array<class-string, list<ReflectionAttribute<object>>>>
     */
    private array $attributeBuckets = [];

    /**
     * @param null|ReflectionClass<object> $controller
     * @param list<string>                 $throws     Fully-qualified exception class names resolved from
     *                                                 the action's `@throws` lines.
     */
    public function __construct(
        public readonly Route $route,
        public readonly ?ReflectionClass $controller,
        public readonly ?ReflectionMethod $method,
        public readonly ?string $summary,
        public readonly ?string $description,
        public readonly array $throws = [],
        public readonly ?ReflectionFunction $closure = null,
    ) {}

    /**
     * Returns the `ReflectionAttribute`s of the given class declared on the controller class, or an
     * empty list if there is no controller or none are declared.
     *
     * @template T of object
     *
     * @param class-string<T> $attribute
     *
     * @return list<ReflectionAttribute<T>>
     */
    public function controllerAttributes(string $attribute): array
    {
        if ($this->controller === null) {
            return [];
        }

        // Bucket entries indexed by $attribute hold `ReflectionAttribute<$attribute>`
        // by construction; PHPStan cannot follow the per-key narrowing through a
        // single array, so the covariance is asserted here.
        return $this->bucketFor($this->controller)[$attribute] ?? []; // @phpstan-ignore return.type
    }

    /**
     * Returns the `ReflectionAttribute`s of the given class declared on the action reflector
     * (method or closure), or an empty list if there is no action reflector or none are declared.
     *
     * @template T of object
     *
     * @param class-string<T> $attribute
     *
     * @return list<ReflectionAttribute<T>>
     */
    public function actionAttributes(string $attribute): array
    {
        if ($this->actionReflector === null) {
            return [];
        }

        // See {@see controllerAttributes()} for the covariance note.
        return $this->bucketFor($this->actionReflector)[$attribute] ?? []; // @phpstan-ignore return.type
    }

    /**
     * @param ReflectionClass<object>|ReflectionFunctionAbstract $reflector
     *
     * @return array<class-string, list<ReflectionAttribute<object>>>
     */
    private function bucketFor(ReflectionClass|ReflectionFunctionAbstract $reflector): array
    {
        $key = spl_object_id($reflector);

        if (!isset($this->attributeBuckets[$key])) {
            $bucket = [];

            foreach ($reflector->getAttributes() as $attribute) {
                $bucket[$attribute->getName()][] = $attribute;
            }

            $this->attributeBuckets[$key] = $bucket;
        }

        return $this->attributeBuckets[$key];
    }

    /**
     * Returns the constraint for the given parameter name.
     */
    public function constraintFor(string $parameterName): ?string
    {
        return $this->route->wheres[$parameterName] ?? null;
    }

    /**
     * @return list<ReflectionParameter>
     */
    private function resolveUriParameters(): array
    {
        $parameters = [];

        foreach ($this->route->signatureParameters() as $parameter) {
            $uri = $this->route->uri();
            $name = $parameter->getName();

            if (str_contains($uri, "{{$name}}") || str_contains($uri, "{{$name}?}")) {
                $parameters[] = $parameter;
            }
        }

        return $parameters;
    }
}
