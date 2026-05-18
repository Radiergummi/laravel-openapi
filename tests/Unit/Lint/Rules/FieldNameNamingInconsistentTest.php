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
use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\FieldNameNamingInconsistent;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeFieldNamingContext(): LintContext
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

function makeFieldNode(string $name): FieldNode
{
    return new FieldNode(
        name: $name,
        type: 'string',
        required: false,
        nullable: false,
        description: null,
        format: null,
        example: null,
        enum: null,
        children: [],
        examples: [],
        ref: null,
        raw: new OA\Property(['property' => $name, '_context' => new Context()]),
    );
}

it('reports its id and level', function (): void {
    $rule = new FieldNameNamingInconsistent();

    expect($rule->id())->toBe('field.name-naming-inconsistent')
        ->and($rule->level())->toBe(3);
});

it('default (camel): passes a valid camelCase field name', function (): void {
    $rule = new FieldNameNamingInconsistent();
    $context = makeFieldNamingContext();

    $findings = iterator_to_array($rule->checkField(makeFieldNode('createdAt'), $context));

    expect($findings)->toBe([]);
});

it('default (camel): passes a single-word lowercase field name', function (): void {
    $rule = new FieldNameNamingInconsistent();
    $context = makeFieldNamingContext();

    $findings = iterator_to_array($rule->checkField(makeFieldNode('name'), $context));

    expect($findings)->toBe([]);
});

it('default (camel): flags a snake_case field name', function (): void {
    $rule = new FieldNameNamingInconsistent();
    $context = makeFieldNamingContext();

    $findings = iterator_to_array($rule->checkField(makeFieldNode('created_at'), $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.name-naming-inconsistent')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('created_at')
        ->and($findings[0]->message)->toContain('camelCase');
});

it('default (camel): flags a PascalCase field name', function (): void {
    $rule = new FieldNameNamingInconsistent();
    $context = makeFieldNamingContext();

    $findings = iterator_to_array($rule->checkField(makeFieldNode('CreatedAt'), $context));

    expect($findings)->toHaveCount(1);
});

it('snake case: passes a valid snake_case field name', function (): void {
    $rule = new FieldNameNamingInconsistent(IdentifierCase::Snake);
    $context = makeFieldNamingContext();

    $findings = iterator_to_array($rule->checkField(makeFieldNode('created_at'), $context));

    expect($findings)->toBe([]);
});

it('snake case: flags a camelCase field name', function (): void {
    $rule = new FieldNameNamingInconsistent(IdentifierCase::Snake);
    $context = makeFieldNamingContext();

    $findings = iterator_to_array($rule->checkField(makeFieldNode('createdAt'), $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('snake_case');
});

it('provides a fix hint with the example identifier', function (): void {
    $rule = new FieldNameNamingInconsistent();
    $context = makeFieldNamingContext();

    $findings = iterator_to_array($rule->checkField(makeFieldNode('created_at'), $context));

    expect($findings[0]->fixHint)
        ->toContain('camelCase')
        ->toContain(IdentifierCase::Camel->example());
});
