<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\FieldDescriptionMissing;
use Radiergummi\OpenApi\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function makeFieldForDescription(?string $description, ?array $enum = null): FieldNode
{
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
        ->and($rule->severity())->toBe(Severity::Underspecified);
});

it('emits a finding when a field has a missing or blank description', function (?string $description): void {
    $rule = new FieldDescriptionMissing();
    $field = makeFieldForDescription($description);

    expect($rule->checkField($field, OperationNodeFactory::emptyContext()))
        ->toEmitFinding(ruleId: 'field.description-missing', messageContains: 'status');
})->with([
    'null'            => [null],
    'empty string'    => [''],
    'whitespace only' => ['   '],
]);

it('emits no findings when a field has a description', function (): void {
    $rule = new FieldDescriptionMissing();
    $field = makeFieldForDescription('The current status of the resource.');

    $findings = iterator_to_array(
        $rule->checkField($field, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('does not overlap with enum.values-undocumented — plain field with description but no enum emits no finding', function (): void {
    $rule = new FieldDescriptionMissing();
    $field = makeFieldForDescription(
        description: 'A plain string field with a description.',
        enum: null,
    );

    $findings = iterator_to_array(
        $rule->checkField($field, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
