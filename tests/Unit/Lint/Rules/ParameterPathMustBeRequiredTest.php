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
use Radiergummi\OpenApi\Core\Lint\Rules\ParameterPathMustBeRequired;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeParameterPathMustBeRequiredContext(): LintContext
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

function makeParameterNodeForRequiredTest(
    string $name,
    bool $required,
    string $pathUri = '/users/{userId}',
    string $method = 'GET',
): ParameterNode {
    $param = new ParameterNode(
        name: $name,
        required: $required,
        schema: 'integer',
        description: null,
        pattern: null,
        examples: [],
        raw: null,
    );

    $operation = new OperationNode(
        pathUri: $pathUri,
        method: $method,
        operationId: null,
        summary: null,
        description: null,
        deprecated: false,
        parameters: [$param],
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
    );
    $param->linkParent($operation);

    return $param;
}

it('reports its id and level', function (): void {
    $rule = new ParameterPathMustBeRequired();

    expect($rule->id())->toBe('parameter.path-must-be-required')
        ->and($rule->level())->toBe(0);
});

it('emits no finding when all path parameters are required', function (): void {
    $rule = new ParameterPathMustBeRequired();
    $param = makeParameterNodeForRequiredTest('userId', required: true);
    $context = makeParameterPathMustBeRequiredContext();

    $findings = iterator_to_array($rule->checkParameter($param, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when a path parameter is not required', function (): void {
    $rule = new ParameterPathMustBeRequired();
    $param = makeParameterNodeForRequiredTest('userId', required: false);
    $context = makeParameterPathMustBeRequiredContext();

    $findings = iterator_to_array($rule->checkParameter($param, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.path-must-be-required')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('userId')
        ->and($findings[0]->message)->toContain('must be required');
});

it('emits a finding per non-required path parameter', function (): void {
    $rule = new ParameterPathMustBeRequired();
    $context = makeParameterPathMustBeRequiredContext();

    $paramA = makeParameterNodeForRequiredTest('userId', required: false);
    $paramB = makeParameterNodeForRequiredTest('postId', required: false);

    $findingsA = iterator_to_array($rule->checkParameter($paramA, $context));
    $findingsB = iterator_to_array($rule->checkParameter($paramB, $context));

    $findings = [...$findingsA, ...$findingsB];

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('userId')
        ->and($findings[1]->message)->toContain('postId');
});

it('emits no finding for a required path parameter among multiple', function (): void {
    $rule = new ParameterPathMustBeRequired();
    $context = makeParameterPathMustBeRequiredContext();

    $param = makeParameterNodeForRequiredTest('userId', required: true);

    $findings = iterator_to_array($rule->checkParameter($param, $context));

    expect($findings)->toBe([]);
});
