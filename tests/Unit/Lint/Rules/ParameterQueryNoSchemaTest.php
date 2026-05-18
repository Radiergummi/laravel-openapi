<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\ParameterQueryNoSchema;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\QueryParameterNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

function makeQueryNoSchemaContext(): LintContext
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

function makeQueryParameterNodeForSchemaTest(
    string $name,
    ?string $type,
    string $pathUri = '/users',
    string $method = 'GET',
): QueryParameterNode {
    $qp = new QueryParameterNode(
        name: $name,
        required: false,
        type: $type,
        hasSchema: $type !== null,
        style: null,
        explode: null,
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
    $rule = new ParameterQueryNoSchema();

    expect($rule->id())
        ->toBe('parameter.query-no-schema')
        ->and($rule->level())
        ->toBe(0);
});

it('emits no finding when query parameters have a schema', function (): void {
    $rule = new ParameterQueryNoSchema();
    $qp = makeQueryParameterNodeForSchemaTest('filter', type: 'string');
    $context = makeQueryNoSchemaContext();

    $findings = iterator_to_array($rule->checkQueryParameter($qp, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when a query parameter has no schema', function (): void {
    $rule = new ParameterQueryNoSchema();
    $qp = makeQueryParameterNodeForSchemaTest('filter', type: null);
    $context = makeQueryNoSchemaContext();

    $findings = iterator_to_array($rule->checkQueryParameter($qp, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('parameter.query-no-schema')
        ->and($findings[0]->level)
        ->toBe(0)
        ->and($findings[0]->message)
        ->toContain('filter')
        ->and($findings[0]->message)
        ->toContain('no schema')
        ->and($findings[0]->message)
        ->toContain('GET')
        ->and($findings[0]->message)
        ->toContain('/users');
});

it('emits a finding per query parameter without schema', function (): void {
    $rule = new ParameterQueryNoSchema();
    $context = makeQueryNoSchemaContext();

    $qpA = makeQueryParameterNodeForSchemaTest('filter', type: null);
    $qpB = makeQueryParameterNodeForSchemaTest('sort', type: null);

    $findingsA = iterator_to_array($rule->checkQueryParameter($qpA, $context));
    $findingsB = iterator_to_array($rule->checkQueryParameter($qpB, $context));

    $findings = [...$findingsA, ...$findingsB];

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->message)
        ->toContain('filter')
        ->and($findings[1]->message)
        ->toContain('sort');
});

it('emits no finding when a query parameter has an integer type', function (): void {
    $rule = new ParameterQueryNoSchema();
    $qp = makeQueryParameterNodeForSchemaTest('page', type: 'integer');
    $context = makeQueryNoSchemaContext();

    $findings = iterator_to_array($rule->checkQueryParameter($qp, $context));

    expect($findings)->toBe([]);
});

it('emits no finding when a query parameter has a schema with no explicit type but hasSchema=true', function (): void {
    // Reproduces the false positive: a schema may exist with only enum/format/$ref
    // and no explicit `type`. The old code used `type === null` as a proxy for
    // "no schema", which fires incorrectly in this case.
    $rule = new ParameterQueryNoSchema();

    $qp = new QueryParameterNode(
        name: 'status',
        required: false,
        type: null,      // no type extracted — schema uses only enum
        hasSchema: true, // but a schema object IS present
        style: null,
        explode: null,
        description: null,
        enum: ['active', 'inactive'],
        examples: [],
        raw: null,
    );

    $operation = new OperationNode(
        pathUri: '/orders',
        method: 'GET',
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

    $context = makeQueryNoSchemaContext();
    $findings = iterator_to_array($rule->checkQueryParameter($qp, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when a query parameter has no schema at all (hasSchema=false)', function (): void {
    $rule = new ParameterQueryNoSchema();

    $qp = new QueryParameterNode(
        name: 'filter',
        required: false,
        type: null,
        hasSchema: false,
        style: null,
        explode: null,
        description: null,
        enum: null,
        examples: [],
        raw: null,
    );

    $operation = new OperationNode(
        pathUri: '/orders',
        method: 'GET',
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

    $context = makeQueryNoSchemaContext();
    $findings = iterator_to_array($rule->checkQueryParameter($qp, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.query-no-schema');
});
