<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Support;

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use ReflectionClass;
use ReflectionMethod;

/**
 * Builds minimal `ActionDescriptor` fixtures for tests that exercise resolvers and
 * `OperationRule` lint rules. Shared across the plugin-suite and core lint-rule
 * tests so each one does not re-spell the seven-line constructor.
 */
final class ActionDescriptorFactory
{
    /**
     * A minimal descriptor pointed at the given controller method. The route is a
     * placeholder (`GET /x` by default); summary and description default to null.
     * Overrides exist for the few tests that need a different route URI or verb.
     *
     * @param class-string $controller
     * @param list<string> $verbs
     */
    public static function forControllerMethod(
        string $controller,
        string $method,
        string $uri = '/x',
        array $verbs = ['GET'],
        ?string $summary = null,
        ?string $description = null,
    ): ActionDescriptor {
        return self::forRoute(
            route: new Route($verbs, $uri, []),
            controller: $controller,
            method: $method,
            summary: $summary,
            description: $description,
        );
    }

    /**
     * A descriptor for a controller method bound to a caller-built `Route`.
     * Use this when the route carries test-specific state (middleware, route
     * parameters, named bindings) the placeholder route can't represent.
     *
     * @param class-string $controller
     */
    public static function forRoute(
        Route $route,
        string $controller,
        string $method,
        ?string $summary = null,
        ?string $description = null,
    ): ActionDescriptor {
        return new ActionDescriptor(
            route: $route,
            controller: new ReflectionClass($controller),
            method: new ReflectionMethod($controller, $method),
            summary: $summary,
            description: $description,
        );
    }
}
