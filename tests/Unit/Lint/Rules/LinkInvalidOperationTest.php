<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\LinkInvalidOperation;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\LinkNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new LinkInvalidOperation();

    expect($rule->id())->toBe('link.invalid-operation')->and($rule->level())->toBe(1);
});

it('emits no finding when all Link operationIds resolve', function (): void {
    $link = makeLinkInvalidOperationNode(operationId: 'foo.show');
    $context = makeLinkInvalidOperationContext(existingOperationIds: ['foo.show']);

    $findings = iterator_to_array(new LinkInvalidOperation()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when a Link operationId is unknown', function (): void {
    $link = makeLinkInvalidOperationNode(operationId: 'missing.endpoint');
    $context = makeLinkInvalidOperationContext(existingOperationIds: ['foo.show']);

    $findings = iterator_to_array(new LinkInvalidOperation()->checkLink($link, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('link.invalid-operation')
        ->and($findings[0]->level)
        ->toBe(1)
        ->and($findings[0]->message)
        ->toContain('missing.endpoint');
});

it('emits no finding when link has no operationId', function (): void {
    $link = makeLinkInvalidOperationNode(operationId: null);
    $context = makeLinkInvalidOperationContext(existingOperationIds: []);

    $findings = iterator_to_array(new LinkInvalidOperation()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

function makeLinkInvalidOperationNode(?string $operationId): LinkNode
{
    $link = new LinkNode(
        name: 'GetFoo',
        operationId: $operationId,
        operationRef: null,
        parameters: [],
        description: null,
        raw: null,
    );

    $response = new ResponseNode(
        statusCode: 201,
        description: null,
        fields: [],
        examples: [],
        schemaRef: null,
        headers: [],
        links: [$link],
        raw: null,
    );
    $link->linkParent($response);

    $operation = new OperationNode(
        pathUri: '/creator',
        method: 'POST',
        operationId: 'creator',
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [$response],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Post(['_context' => new Context()]),
    );
    $response->linkParent($operation);

    return $link;
}

/**
 * @param list<string> $existingOperationIds
 */
function makeLinkInvalidOperationContext(array $existingOperationIds): LintContext
{
    $operationsByOperationId = [];

    foreach ($existingOperationIds as $id) {
        $operationsByOperationId[$id] = new OperationNode(
            pathUri: '/' . str_replace('.', '/', $id),
            method: 'GET',
            operationId: $id,
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

    $spec = new OA\OpenApi(['_context' => new Context()]);

    return new LintContext(
        api: new ApiNode(
            operations: [],
            components: [],
            webhooks: [],
            declaredTags: [],
            tagDescriptions: [],
            raw: $spec,
        ),
        index: new TreeIndex(
            operationsByOperationId: $operationsByOperationId,
            operationsByRouteKey: [],
            componentsByName: [],
            referencedComponents: [],
            registeredScopes: [],
            knownRuleIds: [],
        ),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}
