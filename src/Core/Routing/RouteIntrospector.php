<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Routing;

use Closure;
use Generator;
use Illuminate\Container\Container;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Radiergummi\OpenApi\Core\Routing\Filters\RouteFilter;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use UnexpectedValueException;

final readonly class RouteIntrospector
{
    /**
     * @param list<RouteFilter> $filters
     */
    public function __construct(
        private Router $router,
        private Container $container,
        private DocCommentParser $parser,
        private ThrowsExtractor $throwsExtractor,
        private array $filters,
    ) {}

    public function reset(): void
    {
        $this->throwsExtractor->reset();
    }

    /**
     * @return Generator<int, ActionDescriptor>
     *
     * @throws ReflectionException
     * @throws UnexpectedValueException
     */
    public function discover(): Generator
    {
        $routes = $this->router->getRoutes();

        if ($routes instanceof RouteCollection) {
            $routes = $routes
                ->toCompiledRouteCollection($this->router, $this->container)
                ->getRoutes();
        }

        foreach ($routes as $route) {
            if ($this->shouldSkip($route)) {
                continue;
            }

            yield $this->describe($route);
        }
    }

    private function shouldSkip(Route $route): bool
    {
        return array_any($this->filters, fn(RouteFilter $filter) => $filter->shouldSkip($route));
    }

    /**
     * @throws ReflectionException
     * @throws UnexpectedValueException
     */
    private function describe(Route $route): ActionDescriptor
    {
        $controllerClass = $route->getControllerClass();
        $actionMethod = $route->getActionMethod();

        if (!$controllerClass || !$actionMethod) {
            $uses = $route->getAction('uses');

            if ($uses instanceof Closure) {
                $fn = new ReflectionFunction($uses);
                $comment = $fn->getDocComment();
                $docComment = $comment !== false ? $this->parser->parse($comment) : null;
                $throws = $this->throwsExtractor->extract($fn);

                return new ActionDescriptor(
                    route: $route,
                    controller: null,
                    method: null,
                    summary: $docComment?->summary,
                    description: $docComment?->description,
                    throws: $throws,
                    closure: $fn,
                );
            }

            return new ActionDescriptor(
                route: $route,
                controller: null,
                method: null,
                summary: null,
                description: null,
            );
        }

        if (!class_exists($controllerClass)) {
            return new ActionDescriptor(
                route: $route,
                controller: null,
                method: null,
                summary: null,
                description: null,
            );
        }

        $classReflection = new ReflectionClass($controllerClass);

        // Invocable controller: getActionMethod() returns the class name itself
        if ($controllerClass === $actionMethod) {
            $reflector = $classReflection;
            $methodReflection = null;
        } else {
            $methodReflection = $classReflection->getMethod($actionMethod);
            $reflector = $methodReflection;
        }

        $comment = $reflector->getDocComment();
        $docComment = $comment !== false ? $this->parser->parse($comment) : null;
        $throws = $this->throwsExtractor->extract($reflector);

        return new ActionDescriptor(
            route: $route,
            controller: $classReflection,
            method: $methodReflection,
            summary: $docComment?->summary,
            description: $docComment?->description,
            throws: $throws,
        );
    }
}
