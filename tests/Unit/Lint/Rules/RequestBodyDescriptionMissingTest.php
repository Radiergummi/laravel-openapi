<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\RequestBodyDescriptionMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\RequestBodyNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;

uses()->group('openapi', 'lint');

function makeRequestBodyDescriptionMissingContext(): LintContext
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

function makeRequestBodyDescriptionMissingNode(?string $description): RequestBodyNode
{
    return new RequestBodyNode(
        contentTypes: ['application/json'],
        required: true,
        fields: [],
        examples: [],
        schemaRef: null,
        description: $description,
        raw: null,
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new RequestBodyDescriptionMissing();

    expect($rule->id())->toBe('request-body.description-missing')
        ->and($rule->level())->toBe(2);
});

it('emits a finding when a request body has no description', function (): void {
    $rule = new RequestBodyDescriptionMissing();
    $requestBody = makeRequestBodyDescriptionMissingNode(description: null);
    $context = makeRequestBodyDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkRequestBody($requestBody, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('request-body.description-missing')
        ->and($findings[0]->level)->toBe(2);
});

it('emits a finding when a request body has an empty description', function (): void {
    $rule = new RequestBodyDescriptionMissing();
    $requestBody = makeRequestBodyDescriptionMissingNode(description: '');
    $context = makeRequestBodyDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkRequestBody($requestBody, $context));

    expect($findings)->toHaveCount(1);
});

it('emits a finding when a request body has a whitespace-only description', function (): void {
    $rule = new RequestBodyDescriptionMissing();
    $requestBody = makeRequestBodyDescriptionMissingNode(description: '   ');
    $context = makeRequestBodyDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkRequestBody($requestBody, $context));

    expect($findings)->toHaveCount(1);
});

it('emits no findings when a request body has a description', function (): void {
    $rule = new RequestBodyDescriptionMissing();
    $requestBody = makeRequestBodyDescriptionMissingNode(description: 'The payload to create a new project.');
    $context = makeRequestBodyDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkRequestBody($requestBody, $context));

    expect($findings)->toBe([]);
});
