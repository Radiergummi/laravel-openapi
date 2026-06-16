<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

use Closure;
use Generator;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Container;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Radiergummi\OpenApi\Events\RouteSkipped;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Inclusion\InclusionEvaluator;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use UnexpectedValueException;

/**
 * Yields one {@see ActionDescriptor} per Laravel route, unconditionally.
 *
 * Filtering is deferred entirely to {@see InclusionEvaluator} so every exclusion emits a
 * {@see RouteSkipped} event and appears in `openapi:why` traces.
 *
 * @internal
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

        // Invocable controller: getActionMethod() returns the class name. Reflect __invoke() so
        // its docblock, return type, and parameters describe the endpoint, not the class.
        if ($controllerClass === $actionMethod) {
            $methodReflection = $classReflection->hasMethod('__invoke')
                ? $classReflection->getMethod('__invoke')
                : null;
            $reflector = $methodReflection ?? $classReflection;
        } elseif (!$classReflection->hasMethod($actionMethod)) {
            // Stale route or __call-handled method: degrade rather than throw ReflectionException.
            return new ActionDescriptor(
                route: $route,
                controller: null,
                method: null,
                summary: null,
                description: null,
            );
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
