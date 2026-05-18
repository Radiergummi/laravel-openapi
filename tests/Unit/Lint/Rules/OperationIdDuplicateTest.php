<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\OperationIdDuplicate;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new OperationIdDuplicate();

    expect($rule->id())->toBe('operation.id-duplicate')
        ->and($rule->level())->toBe(0);
});

it('emits no finding when all operationIds are unique', function (): void {
    $rule = new OperationIdDuplicate();
    $context = makeDuplicateContext();

    $operations = makeOperationNodes(['users.index', 'users.show', 'posts.index']);

    foreach ($operations as $operation) {
        iterator_to_array($rule->checkOperation($operation, $context));
    }

    $findings = iterator_to_array($rule->finalize($context));

    expect($findings)->toBe([]);
});

it('emits findings for duplicate operationIds', function (): void {
    $rule = new OperationIdDuplicate();
    $context = makeDuplicateContext();

    $operations = makeOperationNodes(['users.index', 'users.index']);

    foreach ($operations as $operation) {
        iterator_to_array($rule->checkOperation($operation, $context));
    }

    $findings = iterator_to_array($rule->finalize($context));

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->ruleId)->toBe('operation.id-duplicate')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('users.index')
        ->and($findings[0]->message)->toContain('2 occurrences')
        ->and($findings[1]->message)->toContain('users.index');
});

it('emits no finding when operations have no operationId', function (): void {
    $rule = new OperationIdDuplicate();
    $context = makeDuplicateContext();

    $operation = new OperationNode(
        pathUri: '/foo',
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
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
    );

    iterator_to_array($rule->checkOperation($operation, $context));

    $findings = iterator_to_array($rule->finalize($context));

    expect($findings)->toBe([]);
});

it('emits no finding when no operations are visited', function (): void {
    $rule = new OperationIdDuplicate();
    $context = makeDuplicateContext();

    $findings = iterator_to_array($rule->finalize($context));

    expect($findings)->toBe([]);
});

it('reports the correct path and method for each duplicate occurrence', function (): void {
    $rule = new OperationIdDuplicate();
    $context = makeDuplicateContext();

    $op1 = new OperationNode(
        pathUri: '/alpha',
        method: 'GET',
        operationId: 'duplicate.id',
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

    $op2 = new OperationNode(
        pathUri: '/beta',
        method: 'POST',
        operationId: 'duplicate.id',
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
        raw: new OA\Post(['_context' => new Context()]),
    );

    iterator_to_array($rule->checkOperation($op1, $context));
    iterator_to_array($rule->checkOperation($op2, $context));

    $findings = iterator_to_array($rule->finalize($context));

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->location->routeUri)->toBe('/alpha')
        ->and($findings[0]->location->routeMethod)->toBe('GET')
        ->and($findings[1]->location->routeUri)->toBe('/beta')
        ->and($findings[1]->location->routeMethod)->toBe('POST');
});

/**
 * Build a list of OperationNode instances with the given operationIds.
 * Each operation is assigned a unique path.
 *
 * @param list<string> $operationIds
 *
 * @return list<OperationNode>
 */
function makeOperationNodes(array $operationIds): array
{
    $nodes = [];

    foreach ($operationIds as $index => $operationId) {
        $nodes[] = new OperationNode(
            pathUri: '/path-' . $index,
            method: 'GET',
            operationId: $operationId,
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
    }

    return $nodes;
}

/**
 * Build a minimal LintContext for use in duplicate tests.
 */
function makeDuplicateContext(): LintContext
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
