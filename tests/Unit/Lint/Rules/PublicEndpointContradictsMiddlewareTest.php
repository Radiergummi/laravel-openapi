<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Lint\Rules\PublicEndpointContradictsMiddleware;
use Radiergummi\OpenApi\Support\Routing\RouteMiddlewareGatherer;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ControllerMiddlewareAuthController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\PublicEndpointMwClassController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\PublicEndpointMwController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function publicEndpointFindings(string $controller, string $method, array $middleware, string $uri = '/test'): array
{
    $route = new Route(['GET'], $uri, [$controller, $method]);
    $route->middleware($middleware);

    $descriptor = ActionDescriptorFactory::forRoute($route, $controller, $method);
    $operation = OperationNodeFactory::forDescriptor($descriptor);

    return iterator_to_array(
        new PublicEndpointContradictsMiddleware(app(RouteMiddlewareGatherer::class))->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );
}

it('reports its id and level', function (): void {
    $rule = new PublicEndpointContradictsMiddleware(app(RouteMiddlewareGatherer::class));

    expect($rule->id())->toBe('publicendpoint.contradicts-middleware')
        ->and($rule->level())->toBe(1);
});

it('emits a finding when a method-level PublicEndpoint has auth middleware', function (): void {
    $findings = publicEndpointFindings(PublicEndpointMwController::class, 'publicAction', ['auth:api']);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('publicendpoint.contradicts-middleware')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('PublicEndpointMwController')
        ->and($findings[0]->message)->toContain('publicAction')
        ->and($findings[0]->message)->toContain('auth:api');
});

it('emits a finding when a method-level PublicEndpoint has scope middleware', function (): void {
    $findings = publicEndpointFindings(PublicEndpointMwController::class, 'publicAction', ['scope:read']);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('scope:read');
});

it('emits a finding when a class-level PublicEndpoint has auth middleware', function (): void {
    $findings = publicEndpointFindings(PublicEndpointMwClassController::class, 'index', ['auth:api', 'scope:write']);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('auth:api')
        ->and($findings[0]->message)->toContain('scope:write');
});

it('emits a finding when controller-applied (HasMiddleware) auth conflicts with PublicEndpoint (#260)', function (): void {
    // No route-declared middleware; `auth:sanctum` is wired on the controller via HasMiddleware.
    // The linter must read the gathered (controller-aware) list, matching the generator.
    $findings = publicEndpointFindings(ControllerMiddlewareAuthController::class, 'publicAction', []);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('auth:sanctum');
});

it('emits no finding', function (string $controller, string $method, array $middleware): void {
    expect(publicEndpointFindings($controller, $method, $middleware))->toBe([]);
})->with([
    'PublicEndpoint method with non-auth middleware' => [PublicEndpointMwController::class, 'publicAction', ['throttle:60,1']],
    'method without PublicEndpoint with auth middleware' => [PublicEndpointMwController::class, 'protectedAction', ['auth:api', 'scope:read']],
]);
