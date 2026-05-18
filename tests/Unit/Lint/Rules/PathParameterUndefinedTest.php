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
use Radiergummi\OpenApi\Core\Lint\Rules\PathParameterUndefined;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new PathParameterUndefined();

    expect($rule->id())->toBe('path.parameter-undefined')->and($rule->level())->toBe(0);
});

it('emits no finding when all path parameters match placeholders', function (): void {
    $operation = makePathParamUndefinedOperation(
        pathUri: '/users/{userId}',
        parameters: [makePathParamUndefinedNode('userId')],
    );

    $findings = iterator_to_array(
        new PathParameterUndefined()->checkOperation($operation, makePathParamUndefinedContext()),
    );

    expect($findings)->toBe([]);
});

it('emits a finding when a path parameter has no matching placeholder', function (): void {
    $operation = makePathParamUndefinedOperation(
        pathUri: '/users/{userId}',
        parameters: [
            makePathParamUndefinedNode('userId'),
            makePathParamUndefinedNode('orphanParam'),
        ],
    );

    $findings = iterator_to_array(
        new PathParameterUndefined()->checkOperation($operation, makePathParamUndefinedContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('path.parameter-undefined')
        ->and($findings[0]->level)
        ->toBe(0)
        ->and($findings[0]->message)
        ->toContain('orphanParam');
});

it('emits no finding when there are no parameters', function (): void {
    $operation = makePathParamUndefinedOperation(pathUri: '/users', parameters: []);

    $findings = iterator_to_array(
        new PathParameterUndefined()->checkOperation($operation, makePathParamUndefinedContext()),
    );

    expect($findings)->toBe([]);
});

it('emits findings for multiple undefined path parameters', function (): void {
    $operation = makePathParamUndefinedOperation(
        pathUri: '/users',
        parameters: [makePathParamUndefinedNode('alpha'), makePathParamUndefinedNode('beta')],
    );

    $findings = iterator_to_array(
        new PathParameterUndefined()->checkOperation($operation, makePathParamUndefinedContext()),
    );

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->message)
        ->toContain('alpha')
        ->and($findings[1]->message)
        ->toContain('beta');
});

/**
 * @param list<ParameterNode> $parameters
 */
function makePathParamUndefinedOperation(
    string $pathUri,
    array $parameters,
    string $method = 'GET',
): OperationNode {
    return new OperationNode(
        pathUri: $pathUri,
        method: $method,
        operationId: 'test.operation',
        summary: null,
        description: null,
        deprecated: false,
        parameters: $parameters,
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
    );
}

function makePathParamUndefinedNode(string $name): ParameterNode
{
    return new ParameterNode(
        name: $name,
        required: true,
        schema: 'string',
        description: null,
        pattern: null,
        examples: [],
        raw: null,
    );
}

function makePathParamUndefinedContext(): LintContext
{
    $ctx = new Context();
    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
    ]);

    $index = new TreeIndex(
        operationsByOperationId: [],
        operationsByRouteKey: [],
        componentsByName: [],
        referencedComponents: [],
        registeredScopes: [],
        knownRuleIds: [],
    );

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: $index,
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}
