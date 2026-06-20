<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\SchemaDescriptionMissing;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new SchemaDescriptionMissing();

    expect($rule->id)->toBe('schema.description-missing')
        ->and($rule->severity)->toBe(Severity::Underspecified);
});

it('emits a finding when a component schema has a missing or blank description', function (?string $description): void {
    $rule = new SchemaDescriptionMissing();
    $schema = OperationNodeFactory::makeComponentSchema(
        name: 'ProjectResource',
        description: $description,
    );

    expect($rule->checkComponentSchema($schema, OperationNodeFactory::emptyContext()))
        ->toEmitFinding(ruleId: 'schema.description-missing', messageContains: 'ProjectResource');
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
