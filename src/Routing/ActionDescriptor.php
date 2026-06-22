<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Routing;

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Support\Routing\UriPlaceholderExtractor;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;

use function array_keys;
use function is_a;
use function str_contains;

/**
 * Holds metadata about a route action, including its attributes, docblock, and URI parameters.
 */
final class ActionDescriptor
{
    /**
     * @var list<ReflectionParameter>
     */
    public array $uriParameters {
        get => $this->resolveUriParameters();
    }

    /**
     * Method for controller actions, closure for closure routes, null when neither is available.
     */
    public ?ReflectionFunctionAbstract $actionReflector {
        get => $this->method ?? $this->closure;
    }
    public ?HttpMethod $httpMethod {
        get => $this->httpMethodOverride ?? HttpMethod::fromString($this->route->methods[0] ?? '');
    }
    /** Pins {@see $httpMethod} to one verb of a multi-verb route; falls back to the route's first verb when null. */
    private ?HttpMethod $httpMethodOverride = null;
    /**
     * Lazily-built attribute buckets keyed by FQCN, to avoid repeated `getAttributes()` walks.
     *
     * @var array<int, array<string, list<ReflectionAttribute<object>>>>
     */
    private array $attributeBuckets = [];

    /**
     * @param null|ReflectionClass<object> $controller
     * @param list<string>                 $throws            Fully-qualified exception class names
     *                                                        resolved from the action's at-throws
     *                                                        lines.
     * @param array<string, string>        $paramDescriptions Action `@param` description text keyed
     *                                                        by parameter name; the lowest-precedence
     *                                                        fallback for a path/query parameter
     *                                                        description.
     */
    public function __construct(
        public readonly Route $route,
        public readonly ?ReflectionClass $controller,
        public readonly ?ReflectionMethod $method,
        public readonly ?string $summary,
        public readonly ?string $description,
        public readonly array $throws = [],
        public readonly ?ReflectionFunction $closure = null,
        public readonly array $paramDescriptions = [],
    ) {}

    /**
     * A copy with {@see $httpMethod} pinned to the given verb.
     */
    public function withHttpMethod(HttpMethod $method): self
    {
        $clone = clone $this;
        $clone->httpMethodOverride = $method;

        return $clone;
    }

    /**
     * Instantiates every `$attribute` on the action reflector and controller class, action first.
     *
     * @template T of object
     *
     * @param class-string<T> $attribute
     *
     * @return list<T>
     */
    public function attributeInstances(string $attribute): array
    {
        $instances = [];

        foreach ($this->actionAttributes($attribute) as $reflectionAttribute) {
            $instances[] = $reflectionAttribute->newInstance();
        }

        foreach ($this->controllerAttributes($attribute) as $reflectionAttribute) {
            $instances[] = $reflectionAttribute->newInstance();
        }

        return $instances;
    }

    /**
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

        /** @var list<ReflectionAttribute<T>> $attributes */
        $attributes = $this->bucketFor($this->actionReflector)[$attribute] ?? [];

        return $attributes;
    }

    /**
     * @param ReflectionClass<object>|ReflectionFunctionAbstract $reflector
     *
     * @return array<string, list<ReflectionAttribute<object>>>
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

        return $this->attributeBuckets[$key] ?? [];
    }

    /**
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

        /** @var list<ReflectionAttribute<T>> $attributes */
        $attributes = $this->bucketFor($this->controller)[$attribute] ?? [];

        return $attributes;
    }

    /**
     * Whether the action or controller declares any attribute implementing `$interface`, letting
     * resolvers detect authoring markers without depending on concrete plugin attribute classes.
     *
     * @param class-string $interface
     */
    public function declaresAttributeImplementing(string $interface): bool
    {
        foreach ([$this->actionReflector, $this->controller] as $reflector) {
            if ($reflector === null) {
                continue;
            }

            if (array_any(
                array_keys($this->bucketFor($reflector)),
                fn(string $attributeClass): bool
                    => is_a($attributeClass, $interface, true),
            )) {
                return true;
            }
        }

        return false;
    }

    public function constraintFor(string $parameterName): ?string
    {
        return $this->route->wheres[$parameterName] ?? null;
    }

    public function bindingFieldFor(string $parameterName): ?string
    {
        return $this->route->bindingFieldFor($parameterName);
    }

    /**
     * Extracts every URI placeholder from the route template as `[bareName, optional]`.
     *
     * @return list<array{string, bool}>
     */
    public function uriPlaceholders(): array
    {
        return UriPlaceholderExtractor::extract($this->route->uri());
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
