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
use OpenApi\Generator;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\ParameterExampleMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ExampleNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeParameterExampleMissingContext(): LintContext
{
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);

    return new LintContext(
        api: new ApiNode(
            operations: [],
            components: [],
            webhooks: [],
            declaredTags: [],
            tagDescriptions: [],
            raw: $spec,
        ),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}

/**
 * Build a ParameterNode with the given example/examples state on its raw OA\Parameter.
 *
 * @param mixed $example  Generator::UNDEFINED for absent, any other value for present.
 * @param mixed $examples Generator::UNDEFINED for absent, an array for present.
 */
function makeParameterExampleMissingNode(mixed $example, mixed $examples): ParameterNode
{
    $oaParam = new OA\PathParameter([
        '_context' => new Context(),
        'name' => 'userId',
    ]);
    $oaParam->example = $example;
    $oaParam->examples = $examples;

    $exampleNodes = [];

    if ($examples !== Generator::UNDEFINED && is_array($examples)) {
        foreach ($examples as $ex) {
            if ($ex instanceof OA\Examples) {
                $exampleNodes[] = new ExampleNode(
                    name: $ex->example !== Generator::UNDEFINED ? (string) $ex->example : null,
                    value: $ex->value !== Generator::UNDEFINED ? $ex->value : null,
                    summary: null,
                    description: null,
                    raw: $ex,
                );
            }
        }
    }

    $node = new ParameterNode(
        name: 'userId',
        required: true,
        schema: 'string',
        description: 'The user identifier.',
        pattern: null,
        examples: $exampleNodes,
        raw: $oaParam,
    );

    $operation = new OperationNode(
        pathUri: '/users/{userId}',
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

it('has the correct rule id and level', function (): void {
    $rule = new ParameterExampleMissing();

    expect($rule->id())->toBe('parameter.example-missing')
        ->and($rule->level())->toBe(4);
});

it('emits a finding when a parameter has neither example nor examples', function (): void {
    $rule = new ParameterExampleMissing();
    $parameter = makeParameterExampleMissingNode(
        example: Generator::UNDEFINED,
        examples: Generator::UNDEFINED,
    );
    $context = makeParameterExampleMissingContext();

    $findings = iterator_to_array($rule->checkParameter($parameter, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.example-missing')
        ->and($findings[0]->level)->toBe(4)
        ->and($findings[0]->message)->toContain('userId');
});

it('emits no finding when a parameter has example (singular)', function (): void {
    $rule = new ParameterExampleMissing();
    $parameter = makeParameterExampleMissingNode(
        example: 'abc123',
        examples: Generator::UNDEFINED,
    );
    $context = makeParameterExampleMissingContext();

    $findings = iterator_to_array($rule->checkParameter($parameter, $context));

    expect($findings)->toBe([]);
});

it('emits no finding when a parameter has examples (plural)', function (): void {
    $rule = new ParameterExampleMissing();

    $oaExample = new OA\Examples([
        '_context' => new Context(),
        'example' => 'user1',
        'value' => 'abc123',
    ]);

    $parameter = makeParameterExampleMissingNode(
        example: Generator::UNDEFINED,
        examples: [$oaExample],
    );
    $context = makeParameterExampleMissingContext();

    $findings = iterator_to_array($rule->checkParameter($parameter, $context));

    expect($findings)->toBe([]);
});

it('emits no finding when raw is null (no OA\\Parameter available)', function (): void {
    $rule = new ParameterExampleMissing();
    $context = makeParameterExampleMissingContext();

    $node = new ParameterNode(
        name: 'userId',
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
