<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\OperationSecurityMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\OperationSecurityMissingController;

uses()->group('openapi', 'lint');

/**
 * Build an OperationNode with the given raw OA\Get operation and descriptor.
 * The `security` field on OperationNode is always derived from the raw object
 * by SpecTreeBuilder; in tests we pass the pre-built array directly.
 */
function makeSecurityMissingOperationNode(
    ActionDescriptor $descriptor,
    OA\Get $raw,
): OperationNode {
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
        raw: $raw,
        webhook: false,
    );
}

function makeSecurityMissingContext(): LintContext
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
    $rule = new OperationSecurityMissing();

    expect($rule->id())->toBe('operation.security-missing')
        ->and($rule->level())->toBe(1);
});

it('emits a finding when a route has auth middleware and no security is declared', function (): void {
    $route = new Route(['GET'], '/protected', [OperationSecurityMissingController::class, 'protectedAction']);
    $route->middleware(['auth:api', 'scope:read']);

    $descriptor = new ActionDescriptor(
        route: $route,
        controller: new ReflectionClass(OperationSecurityMissingController::class),
        method: new ReflectionMethod(OperationSecurityMissingController::class, 'protectedAction'),
        summary: null,
        description: null,
    );

    // Raw operation with security left as UNDEFINED (not declared at all)
    $raw = new OA\Get(['_context' => new Context()]);

    $operation = makeSecurityMissingOperationNode($descriptor, $raw);
    $context = makeSecurityMissingContext();

    $findings = iterator_to_array(
        (new OperationSecurityMissing())->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.security-missing')
        ->and($findings[0]->level)->toBe(1);
});

it('emits a finding when a route has scope middleware and no security is declared', function (): void {
    $route = new Route(['GET'], '/scoped', [OperationSecurityMissingController::class, 'protectedAction']);
    $route->middleware(['scope:projects:read']);

    $descriptor = new ActionDescriptor(
        route: $route,
        controller: new ReflectionClass(OperationSecurityMissingController::class),
        method: new ReflectionMethod(OperationSecurityMissingController::class, 'protectedAction'),
        summary: null,
        description: null,
    );

    $raw = new OA\Get(['_context' => new Context()]);

    $operation = makeSecurityMissingOperationNode($descriptor, $raw);
    $context = makeSecurityMissingContext();

    $findings = iterator_to_array(
        (new OperationSecurityMissing())->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.security-missing');
});

it('emits no finding when the route has no auth middleware', function (): void {
    $route = new Route(['GET'], '/open', [OperationSecurityMissingController::class, 'publicAction']);
    $route->middleware(['throttle:60,1']);

    $descriptor = new ActionDescriptor(
        route: $route,
        controller: new ReflectionClass(OperationSecurityMissingController::class),
        method: new ReflectionMethod(OperationSecurityMissingController::class, 'publicAction'),
        summary: null,
        description: null,
    );

    $raw = new OA\Get(['_context' => new Context()]);

    $operation = makeSecurityMissingOperationNode($descriptor, $raw);
    $context = makeSecurityMissingContext();

    $findings = iterator_to_array(
        (new OperationSecurityMissing())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when #[PublicEndpoint] is present (explicit security: [])', function (): void {
    $route = new Route(['GET'], '/explicit-public', [OperationSecurityMissingController::class, 'explicitlyPublicAction']);
    $route->middleware(['auth:api']);

    $descriptor = new ActionDescriptor(
        route: $route,
        controller: new ReflectionClass(OperationSecurityMissingController::class),
        method: new ReflectionMethod(OperationSecurityMissingController::class, 'explicitlyPublicAction'),
        summary: null,
        description: null,
    );

    // #[PublicEndpoint] emits security: [] — an explicit empty array, not UNDEFINED
    $raw = new OA\Get(['_context' => new Context(), 'security' => []]);

    $operation = makeSecurityMissingOperationNode($descriptor, $raw);
    $context = makeSecurityMissingContext();

    $findings = iterator_to_array(
        (new OperationSecurityMissing())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when the operation has a non-empty security requirement', function (): void {
    $route = new Route(['GET'], '/with-security', [OperationSecurityMissingController::class, 'protectedAction']);
    $route->middleware(['auth:api', 'scope:read']);

    $descriptor = new ActionDescriptor(
        route: $route,
        controller: new ReflectionClass(OperationSecurityMissingController::class),
        method: new ReflectionMethod(OperationSecurityMissingController::class, 'protectedAction'),
        summary: null,
        description: null,
    );

    // Operation already declares a security requirement
    $raw = new OA\Get(['_context' => new Context(), 'security' => [['bearerAuth' => []]]]);

    $operation = makeSecurityMissingOperationNode($descriptor, $raw);
    $context = makeSecurityMissingContext();

    $findings = iterator_to_array(
        (new OperationSecurityMissing())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding for webhooks', function (): void {
    $route = new Route(['GET'], '/webhook', [OperationSecurityMissingController::class, 'protectedAction']);
    $route->middleware(['auth:api']);

    $descriptor = new ActionDescriptor(
        route: $route,
        controller: new ReflectionClass(OperationSecurityMissingController::class),
        method: new ReflectionMethod(OperationSecurityMissingController::class, 'protectedAction'),
        summary: null,
        description: null,
    );

    $raw = new OA\Get(['_context' => new Context()]);

    $operation = new OperationNode(
        pathUri: '/webhook',
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
        raw: $raw,
        webhook: true,
    );

    $context = makeSecurityMissingContext();

    $findings = iterator_to_array(
        (new OperationSecurityMissing())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});
