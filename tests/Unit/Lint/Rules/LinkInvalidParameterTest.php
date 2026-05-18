<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\LinkInvalidParameter;
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
    $rule = new LinkInvalidParameter();

    expect($rule->id())->toBe('link.invalid-parameter')->and($rule->level())->toBe(0);
});

it(
    'emits no finding when all link parameters are accepted by the target operation',
    function (): void {
        $link = makeLinkInvalidParamNode(
            operationId: 'foo.show',
            parameters: ['id' => '$response.body#/id'],
        );
        $context = makeLinkInvalidParamContext(
            targetOperationId: 'foo.show',
            pathParams: ['id'],
            queryParams: [],
        );

        $findings = iterator_to_array(new LinkInvalidParameter()->checkLink($link, $context));

        expect($findings)->toBe([]);
    },
);

it(
    'emits a finding when a link parameter is not accepted by the target operation',
    function (): void {
        $link = makeLinkInvalidParamNode(
            operationId: 'foo.show',
            parameters: ['id' => '$response.body#/id', 'unknown' => 'value'],
        );
        $context = makeLinkInvalidParamContext(
            targetOperationId: 'foo.show',
            pathParams: ['id'],
            queryParams: [],
        );

        $findings = iterator_to_array(new LinkInvalidParameter()->checkLink($link, $context));

        expect($findings)
            ->toHaveCount(1)
            ->and($findings[0]->ruleId)
            ->toBe('link.invalid-parameter')
            ->and($findings[0]->level)
            ->toBe(0)
            ->and($findings[0]->message)
            ->toContain('unknown');
    },
);

it('emits a finding per invalid parameter', function (): void {
    $link = makeLinkInvalidParamNode(
        operationId: 'foo.show',
        parameters: ['bad1' => 'x', 'id' => 'y', 'bad2' => 'z'],
    );
    $context = makeLinkInvalidParamContext(
        targetOperationId: 'foo.show',
        pathParams: ['id'],
        queryParams: [],
    );

    $findings = iterator_to_array(new LinkInvalidParameter()->checkLink($link, $context));

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->message)
        ->toContain('bad1')
        ->and($findings[1]->message)
        ->toContain('bad2');
});

it('accepts both path and query parameters', function (): void {
    $link = makeLinkInvalidParamNode(
        operationId: 'foo.show',
        parameters: ['id' => 'x', 'filter' => 'y'],
    );
    $context = makeLinkInvalidParamContext(
        targetOperationId: 'foo.show',
        pathParams: ['id'],
        queryParams: ['filter'],
    );

    $findings = iterator_to_array(new LinkInvalidParameter()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

it('ignores links without operationId', function (): void {
    $link = makeLinkInvalidParamNode(operationId: null, parameters: ['id' => 'value']);
    $context = makeLinkInvalidParamContext(
        targetOperationId: 'foo.show',
        pathParams: ['id'],
        queryParams: [],
    );

    $findings = iterator_to_array(new LinkInvalidParameter()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

it('skips links that use operationRef instead of operationId (out of scope)', function (): void {
    // Links using operationRef are not validated by this rule; only operationId-based links are
    // checked. A null operationId with a non-null operationRef on the raw link is the real-world
    // scenario, but the rule guards solely on operationId being null.
    $link = makeLinkInvalidParamNode(operationId: null, parameters: ['nonexistent' => 'value']);
    $context = makeLinkInvalidParamContext(
        targetOperationId: 'foo.show',
        pathParams: [],
        queryParams: [],
    );

    $findings = iterator_to_array(new LinkInvalidParameter()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

it('ignores links whose target operation does not exist', function (): void {
    $link = makeLinkInvalidParamNode(operationId: 'nonexistent', parameters: ['id' => 'value']);
    $context = makeLinkInvalidParamContext(
        targetOperationId: 'different.operation',
        pathParams: ['id'],
        queryParams: [],
    );

    $findings = iterator_to_array(new LinkInvalidParameter()->checkLink($link, $context));

    expect($findings)->toBe([]);
});

/**
 * @param array<string, string> $parameters
 */
function makeLinkInvalidParamNode(?string $operationId, array $parameters): LinkNode
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
 * @param list<string> $pathParams
 * @param list<string> $queryParams
 */
function makeLinkInvalidParamContext(
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
        static fn(string $name) => new QueryParameterNode(
            name: $name,
            required: false,
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
