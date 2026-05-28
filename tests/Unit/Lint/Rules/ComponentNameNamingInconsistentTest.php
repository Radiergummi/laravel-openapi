<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\IdentifierCase;
use Radiergummi\OpenApi\Lint\Rules\ComponentNameNamingInconsistent;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new ComponentNameNamingInconsistent();

    expect($rule->id())->toBe('component.name-naming-inconsistent')
        ->and($rule->level())->toBe(3);
});

it('pascal (default): flags non-PascalCase component names', function (string $name, string $expectedCaseLabel): void {
    $rule = new ComponentNameNamingInconsistent(IdentifierCase::Pascal);
    $schema = OperationNodeFactory::makeComponentSchema(name: $name);

    $findings = iterator_to_array($rule->checkComponentSchema($schema, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('component.name-naming-inconsistent')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain($name)
        ->and($findings[0]->message)->toContain($expectedCaseLabel);
})->with([
    'snake_case' => ['project_resource', 'PascalCase'],
    'camelCase'  => ['projectResource', 'PascalCase'],
]);

it('pascal (default): passes a PascalCase component name', function (): void {
    $rule = new ComponentNameNamingInconsistent(IdentifierCase::Pascal);
    $schema = OperationNodeFactory::makeComponentSchema(name: 'ProjectResource');

    $findings = iterator_to_array($rule->checkComponentSchema($schema, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});

it('provides a fix hint with the example identifier', function (): void {
    $rule = new ComponentNameNamingInconsistent();
    $schema = OperationNodeFactory::makeComponentSchema(name: 'project_resource');

    $findings = iterator_to_array($rule->checkComponentSchema($schema, OperationNodeFactory::emptyContext()));

    expect($findings[0]->fixHint)
        ->toContain('PascalCase')
        ->toContain(IdentifierCase::Pascal->example());
});
