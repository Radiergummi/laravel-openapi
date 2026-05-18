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
use Radiergummi\OpenApi\Core\Lint\Rules\TagUndeclaredAtRoot;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeApiNodeForTagUndeclaredAtRoot(array $operationTags, array $rootTags = []): ApiNode
{
    $ctx = new Context();

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
    ]);

    $operations = [];

    foreach ($operationTags as $index => $tags) {
        $operations[] = new OperationNode(
            pathUri: '/path-' . $index,
            method: 'GET',
            operationId: 'op.' . $index,
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
            raw: new OA\Get(['operationId' => 'op.' . $index, '_context' => $ctx]),
        );
    }

    return new ApiNode(
        operations: $operations,
        components: [],
        webhooks: [],
        declaredTags: $rootTags,
        tagDescriptions: [],
        raw: $spec,
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new TagUndeclaredAtRoot();

    expect($rule->id())->toBe('tag.undeclared-at-root')
        ->and($rule->level())->toBe(3);
});

it('emits a finding when a tag is not declared at root', function (): void {
    $rule = new TagUndeclaredAtRoot();
    $api = makeApiNodeForTagUndeclaredAtRoot(
        operationTags: [['Users']],
        rootTags: [],
    );
    $ctx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('tag.undeclared-at-root')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('"Users"');
});

it('emits no findings when all tags are declared at root', function (): void {
    $rule = new TagUndeclaredAtRoot();
    $api = makeApiNodeForTagUndeclaredAtRoot(
        operationTags: [['Users', 'Admin']],
        rootTags: ['Users', 'Admin'],
    );
    $ctx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toBe([]);
});

it('emits findings for each undeclared tag per operation', function (): void {
    $rule = new TagUndeclaredAtRoot();
    $api = makeApiNodeForTagUndeclaredAtRoot(
        operationTags: [['Users', 'Search']],
        rootTags: ['Users'],
    );
    $ctx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('"Search"');
});

it('emits findings across multiple operations', function (): void {
    $rule = new TagUndeclaredAtRoot();
    $api = makeApiNodeForTagUndeclaredAtRoot(
        operationTags: [['MissingA'], ['MissingB']],
        rootTags: [],
    );
    $ctx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toHaveCount(2);
});

it('emits no findings when operations have no tags', function (): void {
    $rule = new TagUndeclaredAtRoot();
    $api = makeApiNodeForTagUndeclaredAtRoot(
        operationTags: [[]],
        rootTags: [],
    );
    $ctx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toBe([]);
});

it('emits no findings when there are no paths', function (): void {
    $rule = new TagUndeclaredAtRoot();
    $api = makeApiNodeForTagUndeclaredAtRoot(
        operationTags: [],
        rootTags: [],
    );
    $ctx = new LintContext(api: $api, index: TreeIndex::empty(), rawSpec: $api->raw, actionDescriptors: [], suppressions: []);

    $findings = iterator_to_array($rule->checkApi($api, $ctx));

    expect($findings)->toBe([]);
});
