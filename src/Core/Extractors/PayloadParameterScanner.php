<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Extractors;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

use function is_a;

/**
 * Scans a controller method's parameter list for candidate request-payload class-strings.
 *
 * Two passes are performed in order:
 *
 * 1. **Direct params** — every parameter of `$method` whose type is a non-builtin
 *    {@see ReflectionNamedType} is included as-is.
 * 2. **Indirection descent** — for every direct param whose class `is_a()` one of the configured
 *    `$indirectionClasses`, that class's constructor is reflected and its non-builtin named-type
 *    parameters are appended.
 */
final class PayloadParameterScanner
{
    /**
     * Per-method result cache, keyed by `Class::method`. The scanner is bound `scoped`, so the
     * cache lives for a single generation run and is discarded with the instance.
     *
     * @var array<string, list<class-string>>
     */
    private array $memo = [];

    /**
     * @param list<class-string> $indirectionClasses Base class-strings whose constructors
     *                                               are also scanned for the payload class.
     *                                               An empty list disables descent entirely.
     */
    public function __construct(
        private readonly array $indirectionClasses = [],
    ) {}

    /**
     * Returns candidate request-payload class-strings in priority order.
     *
     * All direct method-parameter class-strings come first; indirection constructor class-strings
     * follow. Within each group, the left-to-right declaration order is preserved.
     *
     * @return list<class-string>
     */
    public function candidates(ReflectionMethod $method): array
    {
        return $this->memo[$method->class . '::' . $method->name] ??= $this->scan($method);
    }

    /**
     * Returns the first candidate class-string that is a subtype of `$base`, or null.
     *
     * @template T of object
     *
     * @param class-string<T> $base
     *
     * @return null|class-string<T>
     */
    public function candidateOfType(ReflectionMethod $method, string $base): ?string
    {
        return array_find(
            $this->candidates($method),
            fn(string $class) => is_a($class, $base, allow_string: true),
        );
    }

    /**
     * @return list<class-string>
     */
    private function scan(ReflectionMethod $method): array
    {
        /** @var list<class-string> $direct */
        $direct = [];
        /** @var list<class-string> $fromIndirection */
        $fromIndirection = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            /** @var class-string $className */
            $className = $type->getName();
            $direct[] = $className;

            if ($this->indirectionClasses === []) {
                continue;
            }

            foreach ($this->indirectionClasses as $base) {
                if (!is_a($className, $base, allow_string: true)) {
                    continue;
                }

                $constructor = new ReflectionClass($className)->getConstructor();

                if ($constructor === null) {
                    break;
                }

                foreach ($constructor->getParameters() as $ctorParam) {
                    $constructorType = $ctorParam->getType();

                    if (!$constructorType instanceof ReflectionNamedType || $constructorType->isBuiltin()) {
                        continue;
                    }

                    /** @var class-string $constructorClass */
                    $constructorClass = $constructorType->getName();
                    $fromIndirection[] = $constructorClass;
                }

                break;
            }
        }

        return [...$direct, ...$fromIndirection];
    }
}
