<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Support;

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionClass;
use ReflectionMethod;

/**
 * Builds `ActionDescriptor` fixtures with a placeholder route (`GET /x`) for tests that exercise
 * resolvers and `OperationRule` lint rules. Use `forRoute()` when the test needs a real route
 * (middleware, route parameters, named bindings).
 */
final class ActionDescriptorFactory
{
    /**
     * @param class-string $controller
     * @param list<string> $verbs
     */
    public static function forControllerMethod(
        string $controller,
        string $method,
        string $uri = '/x',
        array $verbs = ['GET'],
    ): ActionDescriptor {
        return self::forRoute(
            route: new Route($verbs, $uri, []),
            controller: $controller,
            method: $method,
        );
    }

    /**
     * @param class-string $controller
     */
    public static function forRoute(
        Route $route,
        string $controller,
        string $method,
    ): ActionDescriptor {
        return new ActionDescriptor(
            route: $route,
            controller: new ReflectionClass($controller),
            method: new ReflectionMethod($controller, $method),
            summary: null,
            description: null,
        );
    }

    /**
     * Builds a minimal descriptor whose route carries the given middleware list.
     *
     * @param list<string> $middleware
     */
    public static function withMiddleware(array $middleware): ActionDescriptor
    {
        $route = new Route(['GET'], '/x', []);
        $route->middleware($middleware);

        return new ActionDescriptor(
            route: $route,
            controller: null,
            method: null,
            summary: null,
            description: null,
        );
    }
}
