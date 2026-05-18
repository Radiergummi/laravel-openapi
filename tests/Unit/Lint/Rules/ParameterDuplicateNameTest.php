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
use Radiergummi\OpenApi\Core\Lint\Rules\ParameterDuplicateName;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeParameterDuplicateNameContext(): LintContext
{
    $spec = new OA\OpenApi(['_context' => new Context()]);

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}

/**
 * Build an OperationNode with the given parameter names.
 *
 * @param list<string> $parameterNames
 */
function makeOperationWithParameters(
    array $parameterNames,
    string $pathUri = '/users',
    string $method = 'GET',
): OperationNode {
    $params = [];

    foreach ($parameterNames as $name) {
        $params[] = new ParameterNode(
            name: $name,
            required: true,
            schema: 'string',
            description: null,
            pattern: null,
            examples: [],
            raw: null,
        );
    }

    $operation = new OperationNode(
        pathUri: $pathUri,
        method: $method,
        operationId: null,
        summary: null,
        description: null,
        deprecated: false,
        parameters: $params,
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
    );

    foreach ($params as $param) {
        $param->linkParent($operation);
    }

    return $operation;
}

it('reports its id and level', function (): void {
    $rule = new ParameterDuplicateName();

    expect($rule->id())->toBe('parameter.duplicate-name')
        ->and($rule->level())->toBe(0);
});

it('emits no finding when all parameters are unique', function (): void {
    $rule = new ParameterDuplicateName();
    $operation = makeOperationWithParameters(['userId', 'postId']);
    $context = makeParameterDuplicateNameContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when parameters share the same name', function (): void {
    $rule = new ParameterDuplicateName();
    $operation = makeOperationWithParameters(['filter', 'filter']);
    $context = makeParameterDuplicateNameContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.duplicate-name')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('filter')
        ->and($findings[0]->message)->toContain('2 times');
});

it('emits one finding per duplicate group', function (): void {
    $rule = new ParameterDuplicateName();
    $operation = makeOperationWithParameters(['a', 'a', 'b', 'b']);
    $context = makeParameterDuplicateNameContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('a')
        ->and($findings[1]->message)->toContain('b');
});

it('emits no finding when operation has no parameters', function (): void {
    $rule = new ParameterDuplicateName();
    $operation = makeOperationWithParameters([]);
    $context = makeParameterDuplicateNameContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('reports triple duplicates correctly', function (): void {
    $rule = new ParameterDuplicateName();
    $operation = makeOperationWithParameters(['token', 'token', 'token']);
    $context = makeParameterDuplicateNameContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('3 times');
});
