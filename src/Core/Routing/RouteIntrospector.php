<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Routing;

use Closure;
use Generator;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Container;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Radiergummi\OpenApi\Core\Events\RouteSkipped;
use Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use UnexpectedValueException;

/**
 * Yields one {@see ActionDescriptor} per Laravel route, unconditionally.
 *
 * Route exclusion lives entirely in {@see InclusionEvaluator} — global filters
 * (`config('openapi.filters')`), spec membership, and visibility are all applied there. Keeping
 * introspection unfiltered means every exclusion produces a {@see RouteSkipped} event and a
 * trace entry visible to `openapi:why`, with no hidden "first the introspector also dropped
 * some" stage.
 */
#[Scoped]
final readonly class RouteIntrospector
{
    public function __construct(
        private Router $router,
        private Container $container,
        private DocCommentParser $parser,
        private ThrowsExtractor $throwsExtractor,
    ) {}

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
        } else {
            $routes = $routes->getRoutes();
        }

        foreach ($routes as $route) {
            yield $this->describe($route);
        }
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
