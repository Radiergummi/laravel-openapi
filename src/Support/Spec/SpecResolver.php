<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Spec;

use Illuminate\Container\Attributes\Scoped;
use Radiergummi\OpenApi\Attributes\Spec;
use ReflectionClass;
use ReflectionMethod;

use function array_unique;
use function array_values;
use function assert;

/**
 * Resolves the effective `#[Spec]` names for a route.
 *
 * Returns null when neither the class nor the method carries `#[Spec]` (route uses filter-based
 * assignment). Otherwise returns the union of method-level attributes, or class-level if the
 * method has none. Method attributes shadow class attributes entirely.
 *
 * @internal
 */
#[Scoped]
final readonly class SpecResolver
{
    /**
     * @param null|ReflectionClass<object> $class
     *
     * @return null|list<string>
     */
    public function resolve(?ReflectionClass $class, ?ReflectionMethod $method): ?array
    {
        $methodNames = $method !== null ? $this->collect($method) : null;

        if ($methodNames !== null) {
            return $methodNames;
        }

        $effectiveClass = $class ?? $method?->getDeclaringClass();

        return $effectiveClass !== null ? $this->collect($effectiveClass) : null;
    }

    /**
     * @param ReflectionClass<object>|ReflectionMethod $target
     *
     * @return null|list<string>
     */
    private function collect(ReflectionClass|ReflectionMethod $target): ?array
    {
        $attributes = $target->getAttributes(Spec::class);

        if ($attributes === []) {
            return null;
        }

        $names = [];

        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();
            assert($instance instanceof Spec);
            $names = [...$names, ...$instance->names];
        }

        return array_values(array_unique($names));
    }
}
