<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Routing;

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Enums\HttpMethod;
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
 * Action Descriptor
 *
 * Holds metadata about a route action, including its attributes, docblock, and URI parameters.
 */
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
    public ?HttpMethod $httpMethod {
        get => $this->httpMethodOverride ?? HttpMethod::fromString($this->route->methods[0] ?? '');
    }
    /**
     * Pins {@see $httpMethod} to one verb of a multi-verb route; see {@see withHttpMethod()}.
     * Falls back to the route's first registered verb when null.
     */
    private ?HttpMethod $httpMethodOverride = null;
    /**
     * Buckets of `ReflectionAttribute`s keyed by attribute FQCN. Built lazily on first access via
     * a single `getAttributes()` call per reflector — every caller that asks for a specific
     * attribute class then reads from the bucket rather than walking the reflector's attribute list
     * again. Saves the ~17 attribute walks per route that `OperationBuilder` used to do.
     *
     * @var array<int, array<string, list<ReflectionAttribute<object>>>>
     */
    private array $attributeBuckets = [];

    /**
     * @param null|ReflectionClass<object> $controller
     * @param list<string>                 $throws     Fully-qualified exception class names
     *                                                 resolved from the action's at-throws lines.
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
     * A copy with {@see $httpMethod} pinned to the given verb.
     */
    public function withHttpMethod(HttpMethod $method): self
    {
        $clone = clone $this;
        $clone->httpMethodOverride = $method;

        return $clone;
    }

    /**
     * Instantiate every `$attribute` declared on the action reflector and the controller class,
     * action first. Convenience wrapper over {@see actionAttributes()} + {@see controllerAttributes()}
     * for callers that want concrete attribute objects rather than `ReflectionAttribute` wrappers.
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

        /** @var list<ReflectionAttribute<T>> $attributes */
        $attributes = $this->bucketFor($this->controller)[$attribute] ?? [];

        return $attributes;
    }

    /**
     * Whether the action (or its controller class) declares any attribute whose class implements
     * the given interface. Lets a resolver detect an authoring marker — e.g.
     * `PrimaryResponseAuthoringAttribute` — without knowing the concrete attribute classes, which
     * may live in plugins it must not depend on.
     *
     * @param class-string $interface
     */
    public function declaresAttributeImplementing(string $interface): bool
    {
        foreach ([$this->actionReflector, $this->controller] as $reflector) {
            if ($reflector === null) {
                continue;
            }

            foreach (array_keys($this->bucketFor($reflector)) as $attributeClass) {
                if (is_a($attributeClass, $interface, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns the constraint for the given parameter name.
     */
    public function constraintFor(string $parameterName): ?string
    {
        return $this->route->wheres[$parameterName] ?? null;
    }

    /**
     * Returns the custom binding field for the given parameter name (the `field` in a
     * `{param:field}` route segment), or null when the parameter has no custom key.
     */
    public function bindingFieldFor(string $parameterName): ?string
    {
        return $this->route->bindingFieldFor($parameterName);
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
