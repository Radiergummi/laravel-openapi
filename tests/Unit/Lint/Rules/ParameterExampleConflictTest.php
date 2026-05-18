<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\ParameterExampleConflict;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ExampleNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Generator;

uses()->group('openapi', 'lint');

function makeExampleConflictContext(): LintContext
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
 * Build a ParameterNode wrapping an OA\Parameter with the given example/examples state.
 *
 * @param mixed $example  Pass Generator::UNDEFINED for absent, or any value for present.
 * @param mixed $examples Pass Generator::UNDEFINED for absent, or an array for present.
 */
function makeParameterNodeWithExamples(mixed $example, mixed $examples): ParameterNode
{
    $oaParam = new OA\PathParameter([
        '_context' => new Context(),
        'name' => 'id',
    ]);
    $oaParam->example = $example;
    $oaParam->examples = $examples;

    $exampleNodes = [];

    if ($examples !== Generator::UNDEFINED && is_array($examples)) {
        foreach ($examples as $ex) {
            if ($ex instanceof OA\Examples) {
                $exampleNodes[] = new ExampleNode(
                    name: $ex->example !== Generator::UNDEFINED ? $ex->example : null,
                    value: $ex->value !== Generator::UNDEFINED ? $ex->value : null,
                    summary: null,
                    description: null,
                    raw: $ex,
                );
            }
        }
    }

    $node = new ParameterNode(
        name: 'id',
        required: true,
        schema: 'string',
        description: null,
        pattern: null,
        examples: $exampleNodes,
        raw: $oaParam,
    );

    $operation = new OperationNode(
        pathUri: '/items/{id}',
        method: 'GET',
        operationId: null,
        summary: null,
        description: null,
        deprecated: false,
        parameters: [$node],
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
    );
    $node->linkParent($operation);

    return $node;
}

it('reports its id, level, and description', function (): void {
    $rule = new ParameterExampleConflict();

    expect($rule->id())->toBe('parameter.example-conflict')
        ->and($rule->level())->toBe(1)
        ->and($rule->description())->toBe('A parameter sets both example and examples (mutually exclusive).');
});

it('emits one finding when parameter has both example and examples set', function (): void {
    $rule = new ParameterExampleConflict();
    $context = makeExampleConflictContext();

    $oaExample = new OA\Examples([
        '_context' => new Context(),
        'example' => 'default',
        'value' => '123',
    ]);
    $node = makeParameterNodeWithExamples(
        example: 'abc',
        examples: [$oaExample],
    );

    $findings = iterator_to_array($rule->checkParameter($node, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.example-conflict')
        ->and($findings[0]->level)->toBe(1);
});

it('emits no finding when only example (singular) is set', function (): void {
    $rule = new ParameterExampleConflict();
    $context = makeExampleConflictContext();

    $node = makeParameterNodeWithExamples(
        example: 'abc',
        examples: Generator::UNDEFINED,
    );

    $findings = iterator_to_array($rule->checkParameter($node, $context));

    expect($findings)->toBe([]);
});

it('emits no finding when only examples (plural) is set', function (): void {
    $rule = new ParameterExampleConflict();
    $context = makeExampleConflictContext();

    $oaExample = new OA\Examples([
        '_context' => new Context(),
        'example' => 'default',
        'value' => '123',
    ]);
    $node = makeParameterNodeWithExamples(
        example: Generator::UNDEFINED,
        examples: [$oaExample],
    );

    $findings = iterator_to_array($rule->checkParameter($node, $context));

    expect($findings)->toBe([]);
});

it('emits no finding when neither example nor examples is set', function (): void {
    $rule = new ParameterExampleConflict();
    $context = makeExampleConflictContext();

    $node = makeParameterNodeWithExamples(
        example: Generator::UNDEFINED,
        examples: Generator::UNDEFINED,
    );

    $findings = iterator_to_array($rule->checkParameter($node, $context));

    expect($findings)->toBe([]);
});

it('emits no finding when raw is null (no OA\\Parameter available)', function (): void {
    $rule = new ParameterExampleConflict();
    $context = makeExampleConflictContext();

    // ParameterNode without a raw OA\Parameter (edge case)
    $node = new ParameterNode(
        name: 'id',
        required: true,
        schema: 'string',
        description: null,
        pattern: null,
        examples: [],
        raw: null,
    );

    $findings = iterator_to_array($rule->checkParameter($node, $context));

    expect($findings)->toBe([]);
});
