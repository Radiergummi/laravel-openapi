<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\OperationSecurityMissing;
use Radiergummi\OpenApi\Support\Routing\RouteMiddlewareGatherer;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ControllerMiddlewareAuthController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\OperationSecurityMissingController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function operationSecurityFindings(
    string $uri,
    string $method,
    array $middleware,
    OA\Operation $raw,
    bool $webhook = false,
    string $controller = OperationSecurityMissingController::class,
): array {
    $route = new Route(['GET'], $uri, [$controller, $method]);
    $route->middleware($middleware);

    $descriptor = ActionDescriptorFactory::forRoute($route, $controller, $method);

    $operation = $webhook
        ? OperationNodeFactory::makeOperation(
            pathUri: $uri,
            responses: [],
            descriptor: $descriptor,
            raw: $raw,
            webhook: true,
        )
        : OperationNodeFactory::forDescriptor($descriptor, raw: $raw);

    return iterator_to_array(
        new OperationSecurityMissing(app(RouteMiddlewareGatherer::class))->checkOperation(
            $operation,
            OperationNodeFactory::emptyContext(),
        ),
    );
}

it('reports its id and level', function (): void {
    $rule = new OperationSecurityMissing(app(RouteMiddlewareGatherer::class));

    expect($rule->id)
        ->toBe('operation.security-missing')
        ->and($rule->severity)->toBe(Severity::Degraded);
});

it('emits a finding when a route has auth middleware and no security is declared', function (): void {
    $findings = operationSecurityFindings(
        '/protected',
        'protectedAction',
        ['auth:api', 'scope:read'],
        new OA\Get(['_context' => new Context()]),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.security-missing')
        ->and($findings[0]->severity)->toBe(Severity::Degraded);
});

it('emits a finding when a route has scope middleware and no security is declared', function (): void {
    $findings = operationSecurityFindings(
        '/scoped',
        'protectedAction',
        ['scope:projects:read'],
        new OA\Get(['_context' => new Context()]),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.security-missing');
});

it(
    'emits a finding when controller-applied (HasMiddleware) auth is present but no security is declared (#260)',
    function (): void {
        // No route-declared middleware; `auth:sanctum` is wired on the controller via HasMiddleware.
        // The linter must read the gathered (controller-aware) list, matching the generator.
        $findings = operationSecurityFindings(
            '/controller-authed',
            'protectedAction',
            [],
            new OA\Get(['_context' => new Context()]),
            false,
            ControllerMiddlewareAuthController::class,
        );

        expect($findings)
            ->toHaveCount(1)
            ->and($findings[0]->ruleId)->toBe('operation.security-missing');
    },
);

it('emits no finding', function (
    string $uri,
    string $method,
    array $middleware,
    OA\Operation $raw,
    bool $webhook = false,
): void {
    expect(operationSecurityFindings($uri, $method, $middleware, $raw, $webhook))->toBe([]);
})->with([
    'route has no auth middleware' => [
        '/open',
        'publicAction',
        ['throttle:60,1'],
        new OA\Get(['_context' => new Context()]),
        false,
    ],
    '#[PublicEndpoint] is present (explicit security: [])' => [
        '/explicit-public',
        'explicitlyPublicAction',
        ['auth:api'],
        new OA\Get(['_context' => new Context(), 'security' => []]),
        false,
    ],
    'operation has a non-empty security requirement' => [
        '/with-security',
        'protectedAction',
        ['auth:api', 'scope:read'],
        new OA\Get(['_context' => new Context(), 'security' => [['bearerAuth' => []]]]),
        false,
    ],
    'operation is a webhook' => [
        '/webhook',
        'protectedAction',
        ['auth:api'],
        new OA\Get(['_context' => new Context()]),
        true,
    ],
]);
