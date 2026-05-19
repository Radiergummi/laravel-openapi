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
     * @param list<string> $throws Fully-qualified exception class names resolved from the action's
     *                             `@throws` lines.
     */
    /**
     * @param ReflectionClass<object>|null $controller
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
