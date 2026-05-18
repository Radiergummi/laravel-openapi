<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\ComponentNameNamingInconsistent;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;

uses()->group('openapi', 'lint');

function makeComponentNamingContext(): LintContext
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

function makeComponentSchemaNode(string $name): ComponentSchemaNode
{
    return new ComponentSchemaNode(
        name: $name,
        description: null,
        fields: [],
        raw: null,
    );
}

it('reports its id and level', function (): void {
    $rule = new ComponentNameNamingInconsistent();

    expect($rule->id())->toBe('component.name-naming-inconsistent')
        ->and($rule->level())->toBe(3);
});

it('pascal (default): flags a snake_case component name', function (): void {
    $rule = new ComponentNameNamingInconsistent(IdentifierCase::Pascal);
    $context = makeComponentNamingContext();

    $findings = iterator_to_array($rule->checkComponentSchema(makeComponentSchemaNode('project_resource'), $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('component.name-naming-inconsistent')
        ->and($findings[0]->level)->toBe(3);
});

it('pascal (default): passes a PascalCase component name', function (): void {
    $rule = new ComponentNameNamingInconsistent(IdentifierCase::Pascal);
    $context = makeComponentNamingContext();

    $findings = iterator_to_array($rule->checkComponentSchema(makeComponentSchemaNode('ProjectResource'), $context));

    expect($findings)->toBe([]);
});

it('pascal (default): flags a camelCase component name', function (): void {
    $rule = new ComponentNameNamingInconsistent();
    $context = makeComponentNamingContext();

    $findings = iterator_to_array($rule->checkComponentSchema(makeComponentSchemaNode('projectResource'), $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('projectResource')
        ->and($findings[0]->message)->toContain('PascalCase');
});

it('provides a fix hint with the example identifier', function (): void {
    $rule = new ComponentNameNamingInconsistent();
    $context = makeComponentNamingContext();

    $findings = iterator_to_array($rule->checkComponentSchema(makeComponentSchemaNode('project_resource'), $context));

    expect($findings[0]->fixHint)
        ->toContain('PascalCase')
        ->toContain(IdentifierCase::Pascal->example());
});
