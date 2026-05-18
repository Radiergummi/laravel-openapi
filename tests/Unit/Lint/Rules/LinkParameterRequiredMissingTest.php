<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\LinkParameterRequiredMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\LinkNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Core\Lint\Tree\QueryParameterNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new LinkParameterRequiredMissing();

    expect($rule->id())->toBe('link.parameter-required-missing')->and($rule->level())->toBe(0);
});

it('emits no finding when all required parameters are supplied', function (): void {
    $link = makeLinkRequiredMissingNode(
        operationId: 'foo.show',
        parameters: ['id' => '$response.body#/id'],
    );
    $context = makeLinkRequiredMissingContext(
        targetOperationId: 'foo.show',
        pathParams: ['id'],
        queryParams: [],
    );

    $findings = iterator_to_array(new LinkParameterRequiredMissing()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when a required parameter is missing', function (): void {
    $link = makeLinkRequiredMissingNode(operationId: 'foo.show', parameters: []);
    $context = makeLinkRequiredMissingContext(
        targetOperationId: 'foo.show',
        pathParams: ['id'],
        queryParams: [],
    );

    $findings = iterator_to_array(new LinkParameterRequiredMissing()->checkLink($link, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('link.parameter-required-missing')
        ->and($findings[0]->level)
        ->toBe(0)
        ->and($findings[0]->message)
        ->toContain('id');
});

it('treats path parameters as always required', function (): void {
    $link = makeLinkRequiredMissingNode(operationId: 'foo.show', parameters: []);
    $context = makeLinkRequiredMissingContext(
        targetOperationId: 'foo.show',
        pathParams: ['slug'],
        queryParams: [],
    );

    $findings = iterator_to_array(new LinkParameterRequiredMissing()->checkLink($link, $context));

    expect($findings)->toHaveCount(1)->and($findings[0]->message)->toContain('slug');
});

it('does not flag optional query parameters as missing', function (): void {
    $link = makeLinkRequiredMissingNode(operationId: 'foo.show', parameters: []);
    $context = makeLinkRequiredMissingContext(
        targetOperationId: 'foo.show',
        pathParams: [],
        queryParams: [['name' => 'filter', 'required' => false]],
    );

    $findings = iterator_to_array(new LinkParameterRequiredMissing()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

it('emits a finding per missing required parameter', function (): void {
    $link = makeLinkRequiredMissingNode(operationId: 'foo.show', parameters: []);
    $context = makeLinkRequiredMissingContext(
        targetOperationId: 'foo.show',
        pathParams: ['id'],
        queryParams: [['name' => 'version', 'required' => true]],
    );

    $findings = iterator_to_array(new LinkParameterRequiredMissing()->checkLink($link, $context));

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->message)
        ->toContain('id')
        ->and($findings[1]->message)
        ->toContain('version');
});

it('emits no finding when target operation has no parameters', function (): void {
    $link = makeLinkRequiredMissingNode(operationId: 'foo.index', parameters: []);
    $context = makeLinkRequiredMissingContext(
        targetOperationId: 'foo.index',
        pathParams: [],
        queryParams: [],
    );

    $findings = iterator_to_array(new LinkParameterRequiredMissing()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

it('skips links that use operationRef instead of operationId (out of scope)', function (): void {
    // Links using operationRef are not validated by this rule; only operationId-based links are
    // checked. A null operationId with a non-null operationRef on the raw link is the real-world
    // scenario, but the rule guards solely on operationId being null.
    $link = makeLinkRequiredMissingNode(operationId: null, parameters: []);
    $context = makeLinkRequiredMissingContext(
        targetOperationId: 'foo.show',
        pathParams: ['id'],
        queryParams: [],
    );

    $findings = iterator_to_array(new LinkParameterRequiredMissing()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

/**
 * @param array<string, string> $parameters
 */
function makeLinkRequiredMissingNode(?string $operationId, array $parameters): LinkNode
{
    $link = new LinkNode(
        name: 'TestLink',
        operationId: $operationId,
        operationRef: null,
        parameters: $parameters,
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
 * @param list<string>                              $pathParams
 * @param list<array{name: string, required: bool}> $queryParams
 */
function makeLinkRequiredMissingContext(
    string $targetOperationId,
    array $pathParams,
    array $queryParams,
): LintContext {
    $parameterNodes = array_map(
        static fn(string $name) => new ParameterNode(
            name: $name,
            required: true,
            schema: null,
            description: null,
            pattern: null,
            examples: [],
            raw: null,
        ),
        $pathParams,
    );

    $queryParameterNodes = array_map(
        static fn(array $qp) => new QueryParameterNode(
            name: $qp['name'],
            required: $qp['required'],
            type: null,
            hasSchema: false,
            style: null,
            explode: null,
            description: null,
            enum: null,
            examples: [],
            raw: null,
        ),
        $queryParams,
    );

    $targetOp = new OperationNode(
        pathUri: '/target',
        method: 'GET',
        operationId: $targetOperationId,
        summary: null,
        description: null,
        deprecated: false,
        parameters: $parameterNodes,
        queryParameters: $queryParameterNodes,
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
    );

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
            operationsByOperationId: [$targetOperationId => $targetOp],
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
