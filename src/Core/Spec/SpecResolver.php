<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Spec;

use Illuminate\Container\Attributes\Scoped;
use Radiergummi\OpenApi\Core\Attributes\Spec;
use ReflectionClass;
use ReflectionMethod;

use function array_unique;
use function array_values;
use function assert;

/**
 * Resolves the effective list of spec names declared by `#[Spec]` attributes on a route.
 *
 * Returns:
 * - `null` when neither the controller class nor the action method carries `#[Spec]`
 *   (i.e. the route is subject to filter-based assignment).
 * - `list<string>` (possibly empty) — the union of all method-level `#[Spec]` attributes if
 *   the method carries any, otherwise the union of all class-level attributes.
 *
 * Method presence shadows the class: a method carrying `#[Spec]` ignores the class's
 * `#[Spec]` entirely, even if the union would differ.
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

        // Fall back to the method's declaring class if no explicit class was passed.
        $effectiveClass = $class ?? $method?->getDeclaringClass();

        return $effectiveClass !== null ? $this->collect($effectiveClass) : null;
    }

    /**
     * @param ReflectionClass<object>|ReflectionMethod $target
     *
     * @return null|list<string> null when no attribute present
     */
    private function collect(ReflectionClass|ReflectionMethod $target): ?array
    {
        $attributes = $target->getAttributes(Spec::class);

        if ($attributes === []) {
            return null;
        }

        $names = [];

        foreach ($attributes as $attr) {
            $instance = $attr->newInstance();
            assert($instance instanceof Spec);
            $names = [...$names, ...$instance->names];
        }

        return array_values(array_unique($names));
    }
}
