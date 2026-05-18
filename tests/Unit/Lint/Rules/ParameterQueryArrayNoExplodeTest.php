<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\ParameterQueryArrayNoExplode;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\QueryParameterNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

function makeQueryArrayNoExplodeContext(): LintContext
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

function makeQueryParameterNodeForArrayTest(
    string $name,
    ?string $type,
    ?string $style = null,
    ?bool $explode = null,
    string $pathUri = '/items',
    string $method = 'GET',
): QueryParameterNode {
    $qp = new QueryParameterNode(
        name: $name,
        required: false,
        type: $type,
        hasSchema: $type !== null,
        style: $style,
        explode: $explode,
        description: null,
        enum: null,
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
        parameters: [],
        queryParameters: [$qp],
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
    );
    $qp->linkParent($operation);

    return $qp;
}

it('reports its id and level', function (): void {
    $rule = new ParameterQueryArrayNoExplode();

    expect($rule->id())
        ->toBe('parameter.query-array-no-explode')
        ->and($rule->level())
        ->toBe(1);
});

it('emits no finding when a query array parameter has explode set', function (): void {
    $rule = new ParameterQueryArrayNoExplode();
    $qp = makeQueryParameterNodeForArrayTest('ids', type: 'array', explode: true);
    $context = makeQueryArrayNoExplodeContext();

    $findings = iterator_to_array($rule->checkQueryParameter($qp, $context));

    expect($findings)->toBe([]);
});

it('emits no finding when a query array parameter has style set', function (): void {
    $rule = new ParameterQueryArrayNoExplode();
    $qp = makeQueryParameterNodeForArrayTest('ids', type: 'array', style: 'form');
    $context = makeQueryArrayNoExplodeContext();

    $findings = iterator_to_array($rule->checkQueryParameter($qp, $context));

    expect($findings)->toBe([]);
});

it('emits no finding for a non-array query parameter', function (): void {
    $rule = new ParameterQueryArrayNoExplode();
    $qp = makeQueryParameterNodeForArrayTest('name', type: 'string');
    $context = makeQueryArrayNoExplodeContext();

    $findings = iterator_to_array($rule->checkQueryParameter($qp, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when a query array parameter lacks both style and explode', function (): void {
    $rule = new ParameterQueryArrayNoExplode();
    $qp = makeQueryParameterNodeForArrayTest('ids', type: 'array');
    $context = makeQueryArrayNoExplodeContext();

    $findings = iterator_to_array($rule->checkQueryParameter($qp, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('parameter.query-array-no-explode')
        ->and($findings[0]->level)
        ->toBe(1)
        ->and($findings[0]->message)
        ->toContain('ids')
        ->and($findings[0]->message)
        ->toContain('style')
        ->and($findings[0]->message)
        ->toContain('explode');
});

it('emits no finding when type is null', function (): void {
    $rule = new ParameterQueryArrayNoExplode();
    $qp = makeQueryParameterNodeForArrayTest('ids', type: null);
    $context = makeQueryArrayNoExplodeContext();

    $findings = iterator_to_array($rule->checkQueryParameter($qp, $context));

    expect($findings)->toBe([]);
});
