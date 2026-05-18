<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\FieldDescriptionMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;

uses()->group('openapi', 'lint');

function makeFieldDescriptionMissingContext(): LintContext
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

function makeFieldDescriptionMissingNode(
    ?string $description,
    ?array $enum = null,
): FieldNode {
    return new FieldNode(
        name: 'status',
        type: 'string',
        required: false,
        nullable: false,
        description: $description,
        format: null,
        example: null,
        enum: $enum,
        children: [],
        examples: [],
        ref: null,
        raw: null,
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new FieldDescriptionMissing();

    expect($rule->id())->toBe('field.description-missing')
        ->and($rule->level())->toBe(2);
});

it('emits a finding when a field has no description', function (): void {
    $rule = new FieldDescriptionMissing();
    $field = makeFieldDescriptionMissingNode(description: null);
    $context = makeFieldDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.description-missing')
        ->and($findings[0]->level)->toBe(2)
        ->and($findings[0]->message)->toContain('status');
});

it('emits a finding when a field has an empty description', function (): void {
    $rule = new FieldDescriptionMissing();
    $field = makeFieldDescriptionMissingNode(description: '');
    $context = makeFieldDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toHaveCount(1);
});

it('emits a finding when a field has a whitespace-only description', function (): void {
    $rule = new FieldDescriptionMissing();
    $field = makeFieldDescriptionMissingNode(description: '   ');
    $context = makeFieldDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toHaveCount(1);
});

it('emits no findings when a field has a description', function (): void {
    $rule = new FieldDescriptionMissing();
    $field = makeFieldDescriptionMissingNode(description: 'The current status of the resource.');
    $context = makeFieldDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toBe([]);
});

it('does not overlap with enum.values-undocumented — plain field with description but no enum emits no finding', function (): void {
    $rule = new FieldDescriptionMissing();
    $field = makeFieldDescriptionMissingNode(
        description: 'A plain string field with a description.',
        enum: null,
    );
    $context = makeFieldDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toBe([]);
});
