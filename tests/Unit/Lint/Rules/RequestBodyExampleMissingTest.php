<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\RequestBodyExampleMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ExampleNode;
use Radiergummi\OpenApi\Core\Lint\Tree\RequestBodyNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;

uses()->group('openapi', 'lint');

function makeRequestBodyExampleMissingContext(): LintContext
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

/**
 * @param list<ExampleNode> $examples
 */
function makeRequestBodyExampleMissingNode(array $examples = []): RequestBodyNode
{
    return new RequestBodyNode(
        contentTypes: ['application/json'],
        required: true,
        fields: [],
        examples: $examples,
        schemaRef: null,
        description: 'The payload.',
        raw: null,
    );
}

function makeRequestBodyExampleNode(): ExampleNode
{
    return new ExampleNode(
        name: 'default',
        value: ['name' => 'Acme Corp'],
        summary: null,
        description: null,
        raw: null,
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new RequestBodyExampleMissing();

    expect($rule->id())->toBe('request-body.example-missing')
        ->and($rule->level())->toBe(4);
});

it('emits a finding when a request body has no examples', function (): void {
    $rule = new RequestBodyExampleMissing();
    $requestBody = makeRequestBodyExampleMissingNode(examples: []);
    $context = makeRequestBodyExampleMissingContext();

    $findings = iterator_to_array($rule->checkRequestBody($requestBody, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('request-body.example-missing')
        ->and($findings[0]->level)->toBe(4);
});

it('emits no finding when a request body has examples', function (): void {
    $rule = new RequestBodyExampleMissing();
    $requestBody = makeRequestBodyExampleMissingNode(
        examples: [makeRequestBodyExampleNode()],
    );
    $context = makeRequestBodyExampleMissingContext();

    $findings = iterator_to_array($rule->checkRequestBody($requestBody, $context));

    expect($findings)->toBe([]);
});
