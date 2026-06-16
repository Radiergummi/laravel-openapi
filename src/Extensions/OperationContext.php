<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Extensions;

use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

/**
 * Context passed to every registered operation transformer, exposing source information for scoping.
 */
final class OperationContext
{
    /** @var null|class-string */
    public ?string $controllerClass {
        get => $this->descriptor->controller?->getName();
    }

    public ?string $methodName {
        get => $this->descriptor->method?->getName();
    }

    public string $routeUri {
        get => $this->descriptor->route->uri();
    }

    public function __construct(
        public readonly ActionDescriptor $descriptor,
        public readonly HttpMethod $httpMethod,
    ) {}
}
