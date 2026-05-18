<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\RequestBodyNoContent;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\RequestBodyNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;

uses()->group('openapi', 'lint');

function makeNoContentContext(): LintContext
{
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}

function makeRequestBodyWithContentTypes(array $contentTypes): RequestBodyNode
{
    return new RequestBodyNode(
        contentTypes: $contentTypes,
        required: true,
        fields: [],
        examples: [],
        schemaRef: null,
        description: null,
        raw: null,
    );
}

// ---- id / level ------------------------------------------------------------

it('reports the correct id and level', function (): void {
    $rule = new RequestBodyNoContent();

    expect($rule->id())->toBe('request-body.no-content')
        ->and($rule->level())->toBe(1);
});

// ---- positive --------------------------------------------------------------

it('emits a finding when a request body has no media-type entries', function (): void {
    $node = makeRequestBodyWithContentTypes([]);
    $context = makeNoContentContext();

    $findings = iterator_to_array((new RequestBodyNoContent())->checkRequestBody($node, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('request-body.no-content')
        ->and($findings[0]->level)->toBe(1);
});

// ---- negative --------------------------------------------------------------

it('emits no finding when a request body has an application/json entry', function (): void {
    $node = makeRequestBodyWithContentTypes(['application/json']);
    $context = makeNoContentContext();

    $findings = iterator_to_array((new RequestBodyNoContent())->checkRequestBody($node, $context));

    expect($findings)->toBe([]);
});

it('emits no finding when a request body has a multipart/form-data entry', function (): void {
    $node = makeRequestBodyWithContentTypes(['multipart/form-data']);
    $context = makeNoContentContext();

    $findings = iterator_to_array((new RequestBodyNoContent())->checkRequestBody($node, $context));

    expect($findings)->toBe([]);
});
