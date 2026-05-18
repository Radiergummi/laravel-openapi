<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\ParameterDescriptionMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeParameterDescriptionMissingContext(): LintContext
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

function makeParameterDescriptionMissingNode(?string $description): ParameterNode
{
    return new ParameterNode(
        name: 'filter',
        required: false,
        schema: 'string',
        description: $description,
        pattern: null,
        examples: [],
        raw: null,
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new ParameterDescriptionMissing();

    expect($rule->id())->toBe('parameter.description-missing')
        ->and($rule->level())->toBe(2);
});

it('emits a finding when a parameter has no description', function (): void {
    $rule = new ParameterDescriptionMissing();
    $parameter = makeParameterDescriptionMissingNode(description: null);
    $context = makeParameterDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkParameter($parameter, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.description-missing')
        ->and($findings[0]->level)->toBe(2)
        ->and($findings[0]->message)->toContain('filter');
});

it('emits a finding when a parameter has an empty description', function (): void {
    $rule = new ParameterDescriptionMissing();
    $parameter = makeParameterDescriptionMissingNode(description: '');
    $context = makeParameterDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkParameter($parameter, $context));

    expect($findings)->toHaveCount(1);
});

it('emits a finding when a parameter has a whitespace-only description', function (): void {
    $rule = new ParameterDescriptionMissing();
    $parameter = makeParameterDescriptionMissingNode(description: '   ');
    $context = makeParameterDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkParameter($parameter, $context));

    expect($findings)->toHaveCount(1);
});

it('emits no findings when a parameter has a description', function (): void {
    $rule = new ParameterDescriptionMissing();
    $parameter = makeParameterDescriptionMissingNode(description: 'Filter results by status.');
    $context = makeParameterDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkParameter($parameter, $context));

    expect($findings)->toBe([]);
});
