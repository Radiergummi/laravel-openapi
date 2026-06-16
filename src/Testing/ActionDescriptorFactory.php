<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Testing;

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Throwable;

/**
 * Builds {@see ActionDescriptor} instances for tests in a single call.
 * Any field can be overridden via named arguments.
 */
final class ActionDescriptorFactory
{
    /**
     * @param class-string                  $controller
     * @param list<string>                  $httpMethods
     * @param list<class-string<Throwable>> $throws
     *
     * @throws ReflectionException If the controller class or method cannot be reflected.
     */
    public static function make(
        string $controller,
        string $method,
        string $uri = '/__test__',
        array $httpMethods = ['GET'],
        ?string $summary = null,
        ?string $description = null,
        array $throws = [],
    ): ActionDescriptor {
        $route = new Route(
            $httpMethods,
            $uri,
            ['controller' => "{$controller}@{$method}"],
        );

        return new ActionDescriptor(
            route: $route,
            controller: new ReflectionClass($controller),
            method: new ReflectionMethod($controller, $method),
            summary: $summary,
            description: $description,
            throws: $throws,
        );
    }
}
