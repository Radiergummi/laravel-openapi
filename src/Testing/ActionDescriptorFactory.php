<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Testing;

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Throwable;

/**
 * Constructs {@see ActionDescriptor} instances for plugin tests with a one-line call.
 *
 * Without this helper, every plugin test rebuilds the descriptor by hand:
 *
 * ```php
 * $route = new Route(['GET'], '/test', ['controller' => Foo::class . '@bar']);
 * $method = new ReflectionMethod(Foo::class, 'bar');
 * $descriptor = new ActionDescriptor($route, new ReflectionClass(Foo::class), $method, null, null, []);
 * ```
 *
 * The helper collapses this to:
 *
 * ```php
 * $descriptor = ActionDescriptorFactory::make(Foo::class, 'bar');
 * ```
 *
 * Any field can be overridden by passing a named argument.
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
            ['controller' => $controller . '@' . $method],
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
