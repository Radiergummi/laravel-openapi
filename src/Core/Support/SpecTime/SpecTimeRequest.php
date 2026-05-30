<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Support\SpecTime;

use Illuminate\Container\Container;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/**
 * Instantiates a {@see FormRequest} subclass with a permissive route + user context for spec-time
 * introspection. Lets `rules()` bodies that read `$this->route('foo')->bar` or `$this->user()`
 * run to completion — both resolve to {@see AnyValue}, whose magic-method paths terminate any
 * chained access without throwing.
 *
 * The schema generator only reads the rules array's structure (keys, types, required, file
 * detection); stubbed values inside `Rule::in([...])` etc. are opaque placeholders, not semantic
 * constraints, so the spec is preserved.
 *
 * @internal Used by {@see \Radiergummi\OpenApi\Core\Support\SchemaFromFormRequest}; not part of
 *           the public extension surface.
 */
final class SpecTimeRequest
{
    /**
     * @template T of FormRequest
     *
     * @param class-string<T> $formRequestClass
     *
     * @return T
     *
     * @throws ReflectionException
     */
    public static function wire(string $formRequestClass): FormRequest
    {
        $args = self::resolveConstructorDeps($formRequestClass);
        $instance = new $formRequestClass(...$args);
        self::configure($instance);

        return $instance;
    }

    /**
     * Resolves a class's constructor parameter list against the container so the result can be
     * splatted into `new $class(...$args)`. Used by {@see wire()} to handle FormRequests with
     * typed constructor dependencies (a valid Laravel pattern — the framework injects them at
     * request time via the container).
     *
     * Going through the container directly via `$container->make($class)` is not viable for
     * FormRequests because `FormRequestServiceProvider` registers an `afterResolving` callback
     * that runs `validateResolved()`, which fires the validator pipeline and throws at spec time
     * (no HTTP input). Resolving each constructor arg separately and then `new`ing the class
     * bypasses that callback while still satisfying constructor DI.
     *
     * @param class-string $class
     *
     * @return list<mixed>
     *
     * @throws ReflectionException
     */
    public static function resolveConstructorDeps(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
            return [];
        }

        $container = Container::getInstance();

        return array_map(
            static fn(ReflectionParameter $param): mixed => self::resolveParameter($param, $container),
            $constructor->getParameters(),
        );
    }

    /**
     * @throws ReflectionException
     */
    private static function resolveParameter(ReflectionParameter $param, Container $container): mixed
    {
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            try {
                return $container->make($type->getName());
            } catch (Throwable) {
                // Fall through to default / null below.
            }
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        return null;
    }

    /**
     * Configures the given FormRequest instance with permissive route + user resolvers in place.
     * Use when the caller already holds a class-string-narrowed instance and {@see wire()}'s
     * generic return would erase that narrowing.
     */
    public static function configure(FormRequest $instance): void
    {
        $instance->setRouteResolver(static fn(): SpecTimeRoute => new SpecTimeRoute());
        $instance->setUserResolver(static fn(): AnyValue => AnyValue::instance());
    }
}
