<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\PublicEndpointContradictsMw;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\PublicEndpointMwClassController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\PublicEndpointMwController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

uses()->group('openapi', 'lint');

function makePublicEndpointOperationNode(ActionDescriptor $descriptor): OperationNode
{
    return new OperationNode(
        pathUri: $descriptor->route->uri(),
        method: 'GET',
        operationId: null,
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: $descriptor,
        raw: new OA\Get(['_context' => new Context()]),
        webhook: false,
    );
}

function makeContextForPublicEndpoint(): LintContext
{
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}

it('reports its id and level', function (): void {
    $rule = new PublicEndpointContradictsMw();

    expect($rule->id())->toBe('publicendpoint.contradicts-middleware')
        ->and($rule->level())->toBe(1);
});

it('emits a finding when a method-level PublicEndpoint has auth middleware', function (): void {
    $route = new Route(['GET'], '/public', [PublicEndpointMwController::class, 'publicAction']);
    $route->middleware(['auth:api']);

    $descriptor = ActionDescriptorFactory::forRoute($route, PublicEndpointMwController::class, 'publicAction');

    $operation = makePublicEndpointOperationNode($descriptor);
    $context = makeContextForPublicEndpoint();

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

    $operation = makePublicEndpointOperationNode($descriptor);
    $context = makeContextForPublicEndpoint();

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

    $operation = makePublicEndpointOperationNode($descriptor);
    $context = makeContextForPublicEndpoint();

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

    $operation = makePublicEndpointOperationNode($descriptor);
    $context = makeContextForPublicEndpoint();

    $findings = iterator_to_array(
        (new PublicEndpointContradictsMw())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when a method without PublicEndpoint has auth middleware', function (): void {
    $route = new Route(['GET'], '/protected', [PublicEndpointMwController::class, 'protectedAction']);
    $route->middleware(['auth:api', 'scope:read']);

    $descriptor = ActionDescriptorFactory::forRoute($route, PublicEndpointMwController::class, 'protectedAction');

    $operation = makePublicEndpointOperationNode($descriptor);
    $context = makeContextForPublicEndpoint();

    $findings = iterator_to_array(
        (new PublicEndpointContradictsMw())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});
