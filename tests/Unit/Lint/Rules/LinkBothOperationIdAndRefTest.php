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
use Radiergummi\OpenApi\Core\Lint\Rules\LinkBothOperationIdAndRef;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\LinkNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new LinkBothOperationIdAndRef();

    expect($rule->id())->toBe('link.both-operation-id-and-ref')->and($rule->level())->toBe(0);
});

it('emits no finding when a link has only operationId', function (): void {
    $link = makeLinkBothFieldsNode(operationId: 'foo.show', operationRef: null);
    $context = makeLinkBothFieldsContext();

    $findings = iterator_to_array(new LinkBothOperationIdAndRef()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

it('emits no finding when a link has only operationRef', function (): void {
    $link = makeLinkBothFieldsNode(operationId: null, operationRef: '#/paths/~1foo/get');
    $context = makeLinkBothFieldsContext();

    $findings = iterator_to_array(new LinkBothOperationIdAndRef()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when a link has both operationId and operationRef', function (): void {
    $link = makeLinkBothFieldsNode(operationId: 'foo.show', operationRef: '#/paths/~1foo/get');
    $context = makeLinkBothFieldsContext();

    $findings = iterator_to_array(new LinkBothOperationIdAndRef()->checkLink($link, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('link.both-operation-id-and-ref')
        ->and($findings[0]->level)
        ->toBe(0)
        ->and($findings[0]->message)
        ->toContain('both')
        ->and($findings[0]->message)
        ->toContain('foo.show');
});

it('emits no finding when both are null', function (): void {
    $link = makeLinkBothFieldsNode(operationId: null, operationRef: null);
    $context = makeLinkBothFieldsContext();

    $findings = iterator_to_array(new LinkBothOperationIdAndRef()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

function makeLinkBothFieldsNode(?string $operationId, ?string $operationRef): LinkNode
{
    $link = new LinkNode(
        name: 'GetFoo',
        operationId: $operationId,
        operationRef: $operationRef,
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

function makeLinkBothFieldsContext(): LintContext
{
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
            operationsByOperationId: [],
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
