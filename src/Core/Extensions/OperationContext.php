<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Extensions;

use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;

/**
 * Context passed to every registered operation transformer.
 *
 * The context exposes the source information that transformers need to scope themselves, e.g., only
 * mutate operations for a specific controller class or HTTP method.
 */
final class OperationContext
{
    /**
     * The fully qualified controller class name, or null for closure routes.
     *
     * @var null|class-string
     */
    public ?string $controllerClass {
        get => $this->descriptor->controller?->getName();
    }

    /**
     * The controller method name, or null when no method reflection is available.
     */
    public ?string $methodName {
        get => $this->descriptor->method?->getName();
    }

    /**
     * The raw route URI (without a leading slash), e.g. `api/v0/projects/{project}`.
     */
    public string $routeUri {
        get => $this->descriptor->route->uri();
    }

    public function __construct(
        /**
         * The resolved route descriptor.
         *
         * Gives access to controller, method, and route.
         */
        public readonly ActionDescriptor $descriptor,

        /**
         * The HTTP method in uppercase: GET, POST, PUT, PATCH, DELETE, OPTIONS.
         */
        public readonly string $httpMethod,
    ) {}
}
