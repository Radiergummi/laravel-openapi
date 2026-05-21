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
use Radiergummi\OpenApi\Core\Lint\Rules\FieldNameNamingInconsistent;
use Radiergummi\OpenApi\Core\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function makeFieldNamingNode(string $name): FieldNode
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

it('default (camel): passes a valid camelCase field name', function (string $name): void {
    $rule = new FieldNameNamingInconsistent();

    $findings = iterator_to_array($rule->checkField(makeFieldNamingNode($name), OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
})->with([
    'multi-word camelCase' => ['createdAt'],
    'single lowercase'     => ['name'],
]);

it('default (camel): flags non-camelCase field names', function (string $name): void {
    $rule = new FieldNameNamingInconsistent();

    $findings = iterator_to_array($rule->checkField(makeFieldNamingNode($name), OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.name-naming-inconsistent')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain($name)
        ->and($findings[0]->message)->toContain('camelCase');
})->with([
    'snake_case' => ['created_at'],
    'PascalCase' => ['CreatedAt'],
]);

it('snake case: passes a valid snake_case field name', function (): void {
    $rule = new FieldNameNamingInconsistent(IdentifierCase::Snake);

    $findings = iterator_to_array($rule->checkField(makeFieldNamingNode('created_at'), OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});

it('snake case: flags a camelCase field name', function (): void {
    $rule = new FieldNameNamingInconsistent(IdentifierCase::Snake);

    $findings = iterator_to_array($rule->checkField(makeFieldNamingNode('createdAt'), OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('snake_case');
});

it('provides a fix hint with the example identifier', function (): void {
    $rule = new FieldNameNamingInconsistent();

    $findings = iterator_to_array($rule->checkField(makeFieldNamingNode('created_at'), OperationNodeFactory::emptyContext()));

    expect($findings[0]->fixHint)
        ->toContain('camelCase')
        ->toContain(IdentifierCase::Camel->example());
});
