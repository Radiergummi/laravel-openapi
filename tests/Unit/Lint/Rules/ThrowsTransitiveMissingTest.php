<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\ThrowsTransitiveMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\TransitiveThrowsController;

uses()->group('openapi', 'lint');

function makeTransitiveThrowsDescriptor(string $method, array $throws = []): ActionDescriptor
{
    $reflection = new ReflectionMethod(TransitiveThrowsController::class, $method);
    $route = new Route(['GET'], '/fixture', [TransitiveThrowsController::class, $method]);

    return new ActionDescriptor(
        route: $route,
        controller: $reflection->getDeclaringClass(),
        method: $reflection,
        summary: null,
        description: null,
        throws: $throws,
    );
}

function makeTransitiveThrowsOperationNode(ActionDescriptor $descriptor): OperationNode
{
    return new OperationNode(
        pathUri: '/fixture',
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

function makeContextForTransitiveThrows(): LintContext
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

it('has the correct rule id and level', function (): void {
    $rule = new ThrowsTransitiveMissing();

    expect($rule->id())->toBe('throws.transitive-missing')
        ->and($rule->level())->toBe(1);
});

it('emits a finding when a controller method is missing a transitive @throws', function (): void {
    $rule = new ThrowsTransitiveMissing();
    $descriptor = makeTransitiveThrowsDescriptor('missingThrows');
    $operation = makeTransitiveThrowsOperationNode($descriptor);
    $context = makeContextForTransitiveThrows();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('throws.transitive-missing')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('FakeAction')
        ->and($findings[0]->message)->toContain('RuntimeException')
        ->and($findings[0]->message)->toContain('missingThrows');
});

it('emits no findings when the controller method redeclares the throws', function (): void {
    $rule = new ThrowsTransitiveMissing();
    $descriptor = makeTransitiveThrowsDescriptor('withThrows', [
        'RuntimeException',
    ]);
    $operation = makeTransitiveThrowsOperationNode($descriptor);
    $context = makeContextForTransitiveThrows();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when the method has no action parameters', function (): void {
    $rule = new ThrowsTransitiveMissing();
    $descriptor = makeTransitiveThrowsDescriptor('noAction');
    $operation = makeTransitiveThrowsOperationNode($descriptor);
    $context = makeContextForTransitiveThrows();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});
