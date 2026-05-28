<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Lint\Rules\SchemaEnumEmpty;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new SchemaEnumEmpty();

    expect($rule->id())->toBe('schema.enum-empty')
        ->and($rule->level())->toBe(1);
});

it('emits a finding for a field schema with an empty enum array', function (): void {
    $field = OperationNodeFactory::makeField(name: 'status', enum: []);

    $findings = iterator_to_array(
        new SchemaEnumEmpty()->checkField($field, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.enum-empty')
        ->and($findings[0]->level)->toBe(1);
});

it('emits no finding for a field whose enum is non-empty or absent', function (?array $enum): void {
    $field = OperationNodeFactory::makeField(name: 'status', enum: $enum);

    $findings = iterator_to_array(
        new SchemaEnumEmpty()->checkField($field, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    'non-empty enum' => [['a', 'b']],
    'null enum'      => [null],
]);

it('emits a finding for a component schema with an empty enum array', function (): void {
    $raw = new OA\Schema([]);
    $raw->enum = [];

    $node = OperationNodeFactory::makeComponentSchema(name: 'Status', raw: $raw);

    $findings = iterator_to_array(
        new SchemaEnumEmpty()->checkComponentSchema($node, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.enum-empty')
        ->and($findings[0]->level)->toBe(1);
});

it('emits no finding for a component schema with a non-empty enum', function (): void {
    $raw = new OA\Schema([]);
    $raw->enum = ['active', 'inactive'];

    $node = OperationNodeFactory::makeComponentSchema(name: 'Status', raw: $raw);

    $findings = iterator_to_array(
        new SchemaEnumEmpty()->checkComponentSchema($node, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits no finding for a component schema without an enum key', function (): void {
    $node = OperationNodeFactory::makeComponentSchema(name: 'Status');

    $findings = iterator_to_array(
        new SchemaEnumEmpty()->checkComponentSchema($node, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
