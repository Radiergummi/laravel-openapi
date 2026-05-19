<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\LinkDuplicateName;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\DuplicateLinkNameController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

uses()->group('openapi', 'lint');

function makeLinkDuplicateNameOperation(string $method): OperationNode
{
    $descriptor = ActionDescriptorFactory::forControllerMethod(DuplicateLinkNameController::class, $method, '/fixture');

    return new OperationNode(
        pathUri: '/fixture',
        method: 'GET',
        operationId: 'fixture.' . $method,
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
    );
}

function makeLinkDuplicateNameContext(): LintContext
{
    $spec = new OA\OpenApi(['_context' => new Context()]);

    return new LintContext(
        api: new ApiNode(
            operations: [],
            components: [],
            webhooks: [],
            declaredTags: [],
            tagDescriptions: [],
            raw: $spec,
        ),
        index: new TreeIndex(
            operationsByOperationId: [],
            operationsByRouteKey: [],
            componentsByName: [],
            referencedComponents: [],
            registeredScopes: [],
            knownRuleIds: [],
        ),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}

it('reports its id and level', function (): void {
    $rule = new LinkDuplicateName();

    expect($rule->id())->toBe('link.duplicate-name')->and($rule->level())->toBe(0);
});

it('emits a finding when a method has duplicate link names', function (): void {
    $rule = new LinkDuplicateName();
    $operation = makeLinkDuplicateNameOperation('withDuplicateLinks');
    $context = makeLinkDuplicateNameContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('link.duplicate-name')
        ->and($findings[0]->level)
        ->toBe(0)
        ->and($findings[0]->message)
        ->toContain('"GetProject"')
        ->and($findings[0]->message)
        ->toContain('2 times');
});

it('emits no findings when all link names are unique', function (): void {
    $rule = new LinkDuplicateName();
    $operation = makeLinkDuplicateNameOperation('withUniqueLinks');
    $context = makeLinkDuplicateNameContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no findings when operation has no descriptor', function (): void {
    $rule = new LinkDuplicateName();
    $operation = new OperationNode(
        pathUri: '/no-descriptor',
        method: 'GET',
        operationId: 'no.descriptor',
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
    );
    $context = makeLinkDuplicateNameContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});
