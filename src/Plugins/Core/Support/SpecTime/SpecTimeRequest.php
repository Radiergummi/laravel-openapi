<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support\SpecTime;

use Illuminate\Container\Container;
use Illuminate\Foundation\Http\FormRequest;
use Radiergummi\OpenApi\Plugins\Core\Support\SchemaFromFormRequest;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/**
 * Instantiates a {@see FormRequest} subclass with a permissive route + user context for spec-time
 * introspection. Lets `rules()` bodies that read `$this->route('foo')->bar` or `$this->user()`
 * run to completion. Both resolve to {@see AnyValue}, whose magic-method paths terminate any
 * chained access without throwing.
 *
 * The schema generator only reads the rules array's structure (keys, types, required, file
 * detection); stubbed values inside `Rule::in([...])` etc. are opaque placeholders, not semantic
 * constraints, so the spec is preserved.
 *
 * @internal Used by {@see SchemaFromFormRequest}; not part of the public extension surface.
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
     * Avoids `$container->make($class)` directly: that triggers `FormRequestServiceProvider`'s
     * `afterResolving` callback, which fires `validateResolved()` and throws at spec time.
     * Args are resolved individually and splatted into `new $class(...$args)` instead.
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
     * Installs permissive route and user resolvers. Call directly when the caller holds a
     * narrowed instance and {@see wire()}'s generic return would erase the type.
     */
    public static function configure(FormRequest $instance): void
    {
        $instance->setRouteResolver(static fn(): SpecTimeRoute => new SpecTimeRoute());
        $instance->setUserResolver(static fn(): AnyValue => AnyValue::instance());
    }
}
