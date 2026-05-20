<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\Lint\Rules\PublicEndpointContradictsMw;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\PublicEndpointMwClassController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\PublicEndpointMwController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new PublicEndpointContradictsMw();

    expect($rule->id())->toBe('publicendpoint.contradicts-middleware')
        ->and($rule->level())->toBe(1);
});

it('emits a finding when a method-level PublicEndpoint has auth middleware', function (): void {
    $route = new Route(['GET'], '/public', [PublicEndpointMwController::class, 'publicAction']);
    $route->middleware(['auth:api']);

    $descriptor = ActionDescriptorFactory::forRoute($route, PublicEndpointMwController::class, 'publicAction');

    $operation = OperationNodeFactory::forDescriptor($descriptor);
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(
        (new PublicEndpointContradictsMw())->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('publicendpoint.contradicts-middleware')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('PublicEndpointMwController')
        ->and($findings[0]->message)->toContain('publicAction')
        ->and($findings[0]->message)->toContain('auth:api');
});

it('emits a finding when a method-level PublicEndpoint has scope middleware', function (): void {
    $route = new Route(['GET'], '/public', [PublicEndpointMwController::class, 'publicAction']);
    $route->middleware(['scope:read']);

    $descriptor = ActionDescriptorFactory::forRoute($route, PublicEndpointMwController::class, 'publicAction');

    $operation = OperationNodeFactory::forDescriptor($descriptor);
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(
        (new PublicEndpointContradictsMw())->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('scope:read');
});

it('emits a finding when a class-level PublicEndpoint has auth middleware', function (): void {
    $route = new Route(['GET'], '/class-public', [PublicEndpointMwClassController::class, 'index']);
    $route->middleware(['auth:api', 'scope:write']);

    $descriptor = ActionDescriptorFactory::forRoute($route, PublicEndpointMwClassController::class, 'index');

    $operation = OperationNodeFactory::forDescriptor($descriptor);
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(
        (new PublicEndpointContradictsMw())->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('auth:api')
        ->and($findings[0]->message)->toContain('scope:write');
});

it('emits no finding when a PublicEndpoint method has no auth middleware', function (): void {
    $route = new Route(['GET'], '/public', [PublicEndpointMwController::class, 'publicAction']);
    $route->middleware(['throttle:60,1']);

    $descriptor = ActionDescriptorFactory::forRoute($route, PublicEndpointMwController::class, 'publicAction');

    $operation = OperationNodeFactory::forDescriptor($descriptor);
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(
        (new PublicEndpointContradictsMw())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when a method without PublicEndpoint has auth middleware', function (): void {
    $route = new Route(['GET'], '/protected', [PublicEndpointMwController::class, 'protectedAction']);
    $route->middleware(['auth:api', 'scope:read']);

    $descriptor = ActionDescriptorFactory::forRoute($route, PublicEndpointMwController::class, 'protectedAction');

    $operation = OperationNodeFactory::forDescriptor($descriptor);
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(
        (new PublicEndpointContradictsMw())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});
