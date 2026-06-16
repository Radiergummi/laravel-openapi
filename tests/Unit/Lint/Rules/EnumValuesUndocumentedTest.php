<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\EnumValuesUndocumented;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new EnumValuesUndocumented();

    expect($rule->id())
        ->toBe('enum.values-undocumented')
        ->and($rule->severity())->toBe(Severity::Underspecified);
});

it('emits a finding when an enum field has no description', function (): void {
    $field = OperationNodeFactory::makeField(name: 'Status', enum: ['active', 'inactive']);

    $findings = iterator_to_array(
        new EnumValuesUndocumented()->checkField($field, OperationNodeFactory::emptyContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('enum.values-undocumented')
        ->and($findings[0]->severity)->toBe(Severity::Underspecified)
        ->and($findings[0]->message)->toContain('Status');
});

it('emits a finding when description does not mention any enum values', function (): void {
    $field = OperationNodeFactory::makeField(
        name: 'Status',
        description: 'The current state of the entity.',
        enum: ['active', 'inactive'],
    );

    $findings = iterator_to_array(
        new EnumValuesUndocumented()->checkField($field, OperationNodeFactory::emptyContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->message)->toContain('does not mention');
});

it('emits no findings when the description documents the enum', function (string $description): void {
    $field = OperationNodeFactory::makeField(
        name: 'Status',
        description: $description,
        enum: ['active', 'inactive'],
    );

    $findings = iterator_to_array(
        new EnumValuesUndocumented()->checkField($field, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    'mentions a value' => ['Can be active or inactive.'],
    'dash bullet list' => ["Possible statuses:\n- First option\n- Second option"],
    'asterisk list' => ["Possible statuses:\n* First option\n* Second option"],
]);

it('skips fields without enum values', function (): void {
    $field = OperationNodeFactory::makeField(name: 'PlainString');

    $findings = iterator_to_array(
        new EnumValuesUndocumented()->checkField($field, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
