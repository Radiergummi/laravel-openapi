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
use Radiergummi\OpenApi\Core\Lint\Rules\QueryParamDuplicate;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\QueryParameterNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeQueryParamDuplicateContext(): LintContext
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
 * Build an OperationNode with the given query parameter names.
 *
 * @param list<string> $queryParamNames
 */
function makeOperationWithQueryParams(
    array $queryParamNames,
    string $pathUri = '/fixture',
    string $method = 'GET',
): OperationNode {
    $queryParams = [];

    foreach ($queryParamNames as $name) {
        $queryParams[] = new QueryParameterNode(
            name: $name,
            required: false,
            type: 'string',
            hasSchema: true,
            style: null,
            explode: null,
            description: null,
            enum: null,
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
        parameters: [],
        queryParameters: $queryParams,
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
    );

    foreach ($queryParams as $qp) {
        $qp->linkParent($operation);
    }

    return $operation;
}

it('has the correct rule id and level', function (): void {
    $rule = new QueryParamDuplicate();

    expect($rule->id())->toBe('queryparam.duplicate')
        ->and($rule->level())->toBe(0);
});

it('emits a finding when a method has duplicate query param names', function (): void {
    $rule = new QueryParamDuplicate();
    $operation = makeOperationWithQueryParams(['q', 'q', 'limit']);
    $context = makeQueryParamDuplicateContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('queryparam.duplicate')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('"q"')
        ->and($findings[0]->message)->toContain('2 times');
});

it('emits no findings when all query param names are unique', function (): void {
    $rule = new QueryParamDuplicate();
    $operation = makeOperationWithQueryParams(['q', 'limit', 'offset']);
    $context = makeQueryParamDuplicateContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no findings when operation has no query param attributes', function (): void {
    $rule = new QueryParamDuplicate();
    $operation = makeOperationWithQueryParams([]);
    $context = makeQueryParamDuplicateContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits one finding per duplicate group', function (): void {
    $rule = new QueryParamDuplicate();
    $operation = makeOperationWithQueryParams(['q', 'q', 'filter', 'filter']);
    $context = makeQueryParamDuplicateContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('"q"')
        ->and($findings[1]->message)->toContain('"filter"');
});
