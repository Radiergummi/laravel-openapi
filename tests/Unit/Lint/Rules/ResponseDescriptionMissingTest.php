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
use Radiergummi\OpenApi\Core\Lint\Rules\ResponseDescriptionMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeResponseDescriptionMissingContext(): LintContext
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

function makeResponseNodeForDescriptionTest(
    ?string $description,
    string $pathUri = '/test',
    string $method = 'GET',
    int|string $statusCode = 200,
): ResponseNode {
    $response = new ResponseNode(
        statusCode: $statusCode,
        description: $description,
        fields: [],
        examples: [],
        schemaRef: null,
        headers: [],
        links: [],
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
        queryParameters: [],
        requestBody: null,
        responses: [$response],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
    );
    $response->linkParent($operation);

    return $response;
}

it('has the correct rule id and level', function (): void {
    $rule = new ResponseDescriptionMissing();

    expect($rule->id())->toBe('response.description-missing')->and($rule->level())->toBe(0);
});

it('emits a finding when a response has no description', function (): void {
    $rule = new ResponseDescriptionMissing();
    $response = makeResponseNodeForDescriptionTest(description: null);
    $context = makeResponseDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkResponse($response, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('response.description-missing')
        ->and($findings[0]->level)
        ->toBe(0)
        ->and($findings[0]->message)
        ->toContain('200')
        ->and($findings[0]->message)
        ->toContain('GET');
});

it('emits a finding when a response has an empty description', function (): void {
    $rule = new ResponseDescriptionMissing();
    $response = makeResponseNodeForDescriptionTest(description: '');
    $context = makeResponseDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkResponse($response, $context));

    expect($findings)->toHaveCount(1);
});

it('emits a finding when a response has a whitespace-only description', function (): void {
    $rule = new ResponseDescriptionMissing();
    $response = makeResponseNodeForDescriptionTest(description: '   ');
    $context = makeResponseDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkResponse($response, $context));

    expect($findings)->toHaveCount(1);
});

it('emits no findings when a response has a description', function (): void {
    $rule = new ResponseDescriptionMissing();
    $response = makeResponseNodeForDescriptionTest(description: 'Successful response');
    $context = makeResponseDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkResponse($response, $context));

    expect($findings)->toBe([]);
});
