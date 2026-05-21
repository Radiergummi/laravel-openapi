<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\Rules\SchemaDescriptionMissing;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new SchemaDescriptionMissing();

    expect($rule->id())->toBe('schema.description-missing')
        ->and($rule->level())->toBe(2);
});

it('emits a finding when a component schema has a missing or blank description', function (?string $description): void {
    $rule = new SchemaDescriptionMissing();
    $schema = OperationNodeFactory::makeComponentSchema(
        name: 'ProjectResource',
        description: $description,
    );

    $findings = iterator_to_array(
        $rule->checkComponentSchema($schema, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.description-missing')
        ->and($findings[0]->level)->toBe(2)
        ->and($findings[0]->message)->toContain('ProjectResource');
})->with([
    'null'            => [null],
    'empty string'    => [''],
    'whitespace only' => ['   '],
]);

it('emits no findings when a component schema has a description', function (): void {
    $rule = new SchemaDescriptionMissing();
    $schema = OperationNodeFactory::makeComponentSchema(
        name: 'ProjectResource',
        description: 'Represents a project resource in the API.',
    );

    $findings = iterator_to_array(
        $rule->checkComponentSchema($schema, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
