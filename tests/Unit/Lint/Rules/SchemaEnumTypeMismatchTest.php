<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\SchemaEnumTypeMismatch;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new SchemaEnumTypeMismatch();

    expect($rule->id())
        ->toBe('schema.enum-type-mismatch')
        ->and($rule->severity())->toBe(Severity::Broken);
});

it('emits no finding when all enum values match the declared type', function (string $type, array $enum): void {
    $field = OperationNodeFactory::makeField(name: 'value', type: $type, enum: $enum);

    $findings = iterator_to_array(
        new SchemaEnumTypeMismatch()->checkField($field, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    'integer / ints only' => ['integer', [1, 2, 3]],
    'string / strings only' => ['string', ['red', 'green', 'blue']],
    'number / mixed int and float' => ['number', [1, 2.5, 3]],
    'boolean / bools only' => ['boolean', [true, false]],
]);

it(
    'emits a finding when an enum value does not match the declared type',
    function (string $type, array $enum, int $badIndex): void {
        $field = OperationNodeFactory::makeField(name: 'Status', type: $type, enum: $enum);

        $findings = iterator_to_array(
            new SchemaEnumTypeMismatch()->checkField($field, OperationNodeFactory::emptyContext()),
        );

        expect($findings)
            ->toHaveCount(1)
            ->and($findings[0]->ruleId)->toBe('schema.enum-type-mismatch')
            ->and($findings[0]->severity)->toBe(Severity::Broken)
            ->and($findings[0]->message)->toContain($type)
            ->and($findings[0]->message)->toContain("index {$badIndex}");
    },
)->with([
    'integer with a string' => ['integer', [1, 'two', 3], 1],
    'string with an int' => ['string', ['red', 42], 1],
    'number with a string' => ['number', [1.5, 'high'], 1],
    'boolean with an int' => ['boolean', [true, 0], 1],
]);

it('skips fields without a usable type', function (?string $type, array $enum): void {
    $field = OperationNodeFactory::makeField(name: 'Untyped', type: $type, enum: $enum);

    $findings = iterator_to_array(
        new SchemaEnumTypeMismatch()->checkField($field, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    'no type' => [null, ['a', 1, true]],
    'unsupported type' => ['array', ['a', 'b']],
]);

it('emits multiple findings for multiple mismatched values', function (): void {
    $field = OperationNodeFactory::makeField(name: 'Mixed', type: 'integer', enum: ['one', 2, 'three']);

    $findings = iterator_to_array(
        new SchemaEnumTypeMismatch()->checkField($field, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(2);
});
