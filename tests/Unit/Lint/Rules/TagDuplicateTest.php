<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\TagDuplicate;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new TagDuplicate();

    expect($rule->id())->toBe('tag.duplicate')->and($rule->level())->toBe(0);
});

it('emits a finding when an operation has duplicate tags', function (): void {
    $rule = new TagDuplicate();
    $operation = makeTagDuplicateOperation(tags: ['Search', 'Search', 'Users']);

    $findings = iterator_to_array($rule->checkOperation($operation, makeTagDuplicateContext()));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('tag.duplicate')
        ->and($findings[0]->level)
        ->toBe(0)
        ->and($findings[0]->message)
        ->toContain('"Search"')
        ->and($findings[0]->message)
        ->toContain('2 times');
});

it('emits no findings when all tags are unique', function (): void {
    $rule = new TagDuplicate();
    $operation = makeTagDuplicateOperation(tags: ['Search', 'Users', 'Admin']);

    $findings = iterator_to_array($rule->checkOperation($operation, makeTagDuplicateContext()));

    expect($findings)->toBe([]);
});

it('emits no findings when an operation has no tags', function (): void {
    $rule = new TagDuplicate();
    $operation = makeTagDuplicateOperation(tags: []);

    $findings = iterator_to_array($rule->checkOperation($operation, makeTagDuplicateContext()));

    expect($findings)->toBe([]);
});

it('emits multiple findings for multiple duplicated tags', function (): void {
    $rule = new TagDuplicate();
    $operation = makeTagDuplicateOperation(tags: ['Search', 'Search', 'Admin', 'Admin']);

    $findings = iterator_to_array($rule->checkOperation($operation, makeTagDuplicateContext()));

    expect($findings)->toHaveCount(2);
});

/**
 * @param list<string> $tags
 */
function makeTagDuplicateOperation(array $tags): OperationNode
{
    return new OperationNode(
        pathUri: '/test',
        method: 'GET',
        operationId: 'test.operation',
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: [],
        tags: $tags,
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
    );
}

function makeTagDuplicateContext(): LintContext
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
