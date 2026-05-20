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
use Radiergummi\OpenApi\Core\Lint\Rules\OperationSecurityMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\OperationSecurityMissingController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new OperationSecurityMissing();

    expect($rule->id())->toBe('operation.security-missing')
        ->and($rule->level())->toBe(1);
});

it('emits a finding when a route has auth middleware and no security is declared', function (): void {
    $route = new Route(['GET'], '/protected', [OperationSecurityMissingController::class, 'protectedAction']);
    $route->middleware(['auth:api', 'scope:read']);

    $descriptor = ActionDescriptorFactory::forRoute($route, OperationSecurityMissingController::class, 'protectedAction');

    // Raw operation with security left as UNDEFINED (not declared at all)
    $raw = new OA\Get(['_context' => new Context()]);

    $operation = OperationNodeFactory::forDescriptor($descriptor, raw: $raw);
    $context = OperationNodeFactory::emptyContext();

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

    $descriptor = ActionDescriptorFactory::forRoute($route, OperationSecurityMissingController::class, 'protectedAction');

    $raw = new OA\Get(['_context' => new Context()]);

    $operation = OperationNodeFactory::forDescriptor($descriptor, raw: $raw);
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(
        (new OperationSecurityMissing())->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.security-missing');
});

it('emits no finding when the route has no auth middleware', function (): void {
    $route = new Route(['GET'], '/open', [OperationSecurityMissingController::class, 'publicAction']);
    $route->middleware(['throttle:60,1']);

    $descriptor = ActionDescriptorFactory::forRoute($route, OperationSecurityMissingController::class, 'publicAction');

    $raw = new OA\Get(['_context' => new Context()]);

    $operation = OperationNodeFactory::forDescriptor($descriptor, raw: $raw);
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(
        (new OperationSecurityMissing())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when #[PublicEndpoint] is present (explicit security: [])', function (): void {
    $route = new Route(['GET'], '/explicit-public', [OperationSecurityMissingController::class, 'explicitlyPublicAction']);
    $route->middleware(['auth:api']);

    $descriptor = ActionDescriptorFactory::forRoute($route, OperationSecurityMissingController::class, 'explicitlyPublicAction');

    // #[PublicEndpoint] emits security: [] — an explicit empty array, not UNDEFINED
    $raw = new OA\Get(['_context' => new Context(), 'security' => []]);

    $operation = OperationNodeFactory::forDescriptor($descriptor, raw: $raw);
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(
        (new OperationSecurityMissing())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when the operation has a non-empty security requirement', function (): void {
    $route = new Route(['GET'], '/with-security', [OperationSecurityMissingController::class, 'protectedAction']);
    $route->middleware(['auth:api', 'scope:read']);

    $descriptor = ActionDescriptorFactory::forRoute($route, OperationSecurityMissingController::class, 'protectedAction');

    // Operation already declares a security requirement
    $raw = new OA\Get(['_context' => new Context(), 'security' => [['bearerAuth' => []]]]);

    $operation = OperationNodeFactory::forDescriptor($descriptor, raw: $raw);
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(
        (new OperationSecurityMissing())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding for webhooks', function (): void {
    $route = new Route(['GET'], '/webhook', [OperationSecurityMissingController::class, 'protectedAction']);
    $route->middleware(['auth:api']);

    $descriptor = ActionDescriptorFactory::forRoute($route, OperationSecurityMissingController::class, 'protectedAction');

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

    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array(
        (new OperationSecurityMissing())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});
