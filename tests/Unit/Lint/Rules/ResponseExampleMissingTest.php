<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\ResponseExampleMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ExampleNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;

uses()->group('openapi', 'lint');

function makeResponseExampleMissingContext(): LintContext
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
function makeResponseExampleMissingNode(
    int|string $statusCode = 200,
    array $examples = [],
    bool $noContent = false,
): ResponseNode {
    return new ResponseNode(
        statusCode: $statusCode,
        description: 'OK',
        fields: [],
        examples: $examples,
        schemaRef: $noContent ? null : '#/components/schemas/UserResource',
        headers: [],
        links: [],
        raw: null,
    );
}

function makeExampleNode(string $name = 'default'): ExampleNode
{
    return new ExampleNode(
        name: $name,
        value: ['id' => '123'],
        summary: null,
        description: null,
        raw: null,
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new ResponseExampleMissing();

    expect($rule->id())->toBe('response.example-missing')
        ->and($rule->level())->toBe(4);
});

it('emits a finding when a response has no examples and has content', function (): void {
    $rule = new ResponseExampleMissing();
    $response = makeResponseExampleMissingNode(statusCode: 200, examples: []);
    $context = makeResponseExampleMissingContext();

    $findings = iterator_to_array($rule->checkResponse($response, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('response.example-missing')
        ->and($findings[0]->level)->toBe(4)
        ->and($findings[0]->message)->toContain('200');
});

it('emits no finding when a response has examples', function (): void {
    $rule = new ResponseExampleMissing();
    $response = makeResponseExampleMissingNode(
        statusCode: 200,
        examples: [makeExampleNode()],
    );
    $context = makeResponseExampleMissingContext();

    $findings = iterator_to_array($rule->checkResponse($response, $context));

    expect($findings)->toBe([]);
});

it('emits no finding for a 204 response with no content', function (): void {
    $rule = new ResponseExampleMissing();
    // No schemaRef and no fields = no content
    $response = new ResponseNode(
        statusCode: 204,
        description: 'No Content',
        fields: [],
        examples: [],
        schemaRef: null,
        headers: [],
        links: [],
        raw: null,
    );
    $context = makeResponseExampleMissingContext();

    $findings = iterator_to_array($rule->checkResponse($response, $context));

    expect($findings)->toBe([]);
});
