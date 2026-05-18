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
use Radiergummi\OpenApi\Core\Lint\Rules\HeaderDescriptionMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\HeaderNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeHeaderDescriptionMissingContext(): LintContext
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

function makeHeaderNodeForDescriptionTest(
    string $name,
    ?string $description = null,
    string $pathUri = '/test',
    string $method = 'GET',
): HeaderNode {
    $header = new HeaderNode(
        name: $name,
        schema: 'string',
        description: $description,
        required: false,
        raw: null,
    );

    $response = new ResponseNode(
        statusCode: 200,
        description: 'OK',
        fields: [],
        examples: [],
        schemaRef: null,
        headers: [$header],
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
    $header->linkParent($response);

    return $header;
}

it('has the correct rule id and level', function (): void {
    $rule = new HeaderDescriptionMissing();

    expect($rule->id())->toBe('header.description-missing')->and($rule->level())->toBe(2);
});

it('emits a finding when a response header has no description', function (): void {
    $rule = new HeaderDescriptionMissing();
    $header = makeHeaderNodeForDescriptionTest('X-Request-Id', description: null);
    $context = makeHeaderDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkHeader($header, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('header.description-missing')
        ->and($findings[0]->level)
        ->toBe(2)
        ->and($findings[0]->message)
        ->toContain('X-Request-Id');
});

it('emits a finding when a response header has an empty description', function (): void {
    $rule = new HeaderDescriptionMissing();
    $header = makeHeaderNodeForDescriptionTest('X-Request-Id', description: '');
    $context = makeHeaderDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkHeader($header, $context));

    expect($findings)->toHaveCount(1)->and($findings[0]->message)->toContain('X-Request-Id');
});

it('emits a finding when a response header has a whitespace-only description', function (): void {
    $rule = new HeaderDescriptionMissing();
    $header = makeHeaderNodeForDescriptionTest('X-Request-Id', description: '   ');
    $context = makeHeaderDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkHeader($header, $context));

    expect($findings)->toHaveCount(1);
});

it('emits no findings when a header has a description', function (): void {
    $rule = new HeaderDescriptionMissing();
    $header = makeHeaderNodeForDescriptionTest(
        'X-Request-Id',
        description: 'Unique request identifier',
    );
    $context = makeHeaderDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkHeader($header, $context));

    expect($findings)->toBe([]);
});
