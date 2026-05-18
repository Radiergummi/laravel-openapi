<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\SchemaDescriptionMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;

uses()->group('openapi', 'lint');

function makeSchemaDescriptionMissingContext(): LintContext
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

function makeSchemaDescriptionMissingNode(?string $description): ComponentSchemaNode
{
    return new ComponentSchemaNode(
        name: 'ProjectResource',
        description: $description,
        fields: [],
        raw: null,
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new SchemaDescriptionMissing();

    expect($rule->id())->toBe('schema.description-missing')
        ->and($rule->level())->toBe(2);
});

it('emits a finding when a component schema has no description', function (): void {
    $rule = new SchemaDescriptionMissing();
    $schema = makeSchemaDescriptionMissingNode(description: null);
    $context = makeSchemaDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkComponentSchema($schema, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.description-missing')
        ->and($findings[0]->level)->toBe(2)
        ->and($findings[0]->message)->toContain('ProjectResource');
});

it('emits a finding when a component schema has an empty description', function (): void {
    $rule = new SchemaDescriptionMissing();
    $schema = makeSchemaDescriptionMissingNode(description: '');
    $context = makeSchemaDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkComponentSchema($schema, $context));

    expect($findings)->toHaveCount(1);
});

it('emits a finding when a component schema has a whitespace-only description', function (): void {
    $rule = new SchemaDescriptionMissing();
    $schema = makeSchemaDescriptionMissingNode(description: '   ');
    $context = makeSchemaDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkComponentSchema($schema, $context));

    expect($findings)->toHaveCount(1);
});

it('emits no findings when a component schema has a description', function (): void {
    $rule = new SchemaDescriptionMissing();
    $schema = makeSchemaDescriptionMissingNode(description: 'Represents a project resource in the API.');
    $context = makeSchemaDescriptionMissingContext();

    $findings = iterator_to_array($rule->checkComponentSchema($schema, $context));

    expect($findings)->toBe([]);
});
