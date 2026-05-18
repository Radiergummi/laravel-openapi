<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\PathParameterUndeclared;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new PathParameterUndeclared();

    expect($rule->id())->toBe('path.parameter-undeclared')->and($rule->level())->toBe(0);
});

it('emits no finding when all placeholders have declared path parameters', function (): void {
    $operation = makePathParamUndeclaredOperation(
        pathUri: '/users/{userId}',
        parameters: [makePathParamNode('userId')],
    );

    $findings = iterator_to_array(
        new PathParameterUndeclared()->checkOperation($operation, makePathParamUndeclaredContext()),
    );

    expect($findings)->toBe([]);
});

it('emits a finding for an undeclared path placeholder', function (): void {
    $operation = makePathParamUndeclaredOperation(
        pathUri: '/users/{userId}/posts/{postId}',
        parameters: [makePathParamNode('userId')],
    );

    $findings = iterator_to_array(
        new PathParameterUndeclared()->checkOperation($operation, makePathParamUndeclaredContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('path.parameter-undeclared')
        ->and($findings[0]->level)
        ->toBe(0)
        ->and($findings[0]->message)
        ->toContain('postId');
});

it('emits a finding for each undeclared placeholder', function (): void {
    $operation = makePathParamUndeclaredOperation(
        pathUri: '/users/{userId}/posts/{postId}',
        parameters: [],
    );

    $findings = iterator_to_array(
        new PathParameterUndeclared()->checkOperation($operation, makePathParamUndeclaredContext()),
    );

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->message)
        ->toContain('userId')
        ->and($findings[1]->message)
        ->toContain('postId');
});

it('does not produce a false positive for RFC 6570 operator-prefixed placeholders', function (): void {
    $operation = makePathParamUndeclaredOperation(
        pathUri: '/files/{+path}',
        parameters: [makePathParamNode('path')],
    );

    $findings = iterator_to_array(
        new PathParameterUndeclared()->checkOperation($operation, makePathParamUndeclaredContext()),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when there are no placeholders in the path', function (): void {
    $operation = makePathParamUndeclaredOperation(pathUri: '/users', parameters: []);

    $findings = iterator_to_array(
        new PathParameterUndeclared()->checkOperation($operation, makePathParamUndeclaredContext()),
    );

    expect($findings)->toBe([]);
});

it('does not flag missing parameters for placeholders that are declared', function (): void {
    $operation = makePathParamUndeclaredOperation(
        pathUri: '/users/{userId}',
        parameters: [makePathParamNode('userId')],
    );

    $findings = iterator_to_array(
        new PathParameterUndeclared()->checkOperation($operation, makePathParamUndeclaredContext()),
    );

    expect($findings)->toBe([]);
});

/**
 * @param list<ParameterNode> $parameters
 */
function makePathParamUndeclaredOperation(
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

function makePathParamNode(string $name): ParameterNode
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

function makePathParamUndeclaredContext(): LintContext
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
