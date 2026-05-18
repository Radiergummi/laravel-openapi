<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\SchemaEnumTypeMismatch;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

/**
 * Build a FieldNode with the given type and enum values.
 *
 * @param list<mixed> $enumValues
 */
function makeFieldWithEnum(string $name, ?string $type, array $enumValues): FieldNode
{
    return new FieldNode(
        name: $name,
        type: $type,
        required: false,
        nullable: false,
        description: null,
        format: null,
        example: null,
        enum: $enumValues,
        children: [],
        examples: [],
        ref: null,
        raw: null,
    );
}

function makeContextForEnumTypeMismatch(): LintContext
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

it('reports its id and level', function (): void {
    $rule = new SchemaEnumTypeMismatch();

    expect($rule->id())->toBe('schema.enum-type-mismatch')
        ->and($rule->level())->toBe(0);
});

it('emits no finding when all integer enum values are ints', function (): void {
    $field = makeFieldWithEnum('Status', 'integer', [1, 2, 3]);
    $context = makeContextForEnumTypeMismatch();

    $findings = iterator_to_array((new SchemaEnumTypeMismatch())->checkField($field, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when an integer enum contains a string', function (): void {
    $field = makeFieldWithEnum('Status', 'integer', [1, 'two', 3]);
    $context = makeContextForEnumTypeMismatch();

    $findings = iterator_to_array((new SchemaEnumTypeMismatch())->checkField($field, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.enum-type-mismatch')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('Status')
        ->and($findings[0]->message)->toContain('integer')
        ->and($findings[0]->message)->toContain('index 1');
});

it('emits no finding when all string enum values are strings', function (): void {
    $field = makeFieldWithEnum('Color', 'string', ['red', 'green', 'blue']);
    $context = makeContextForEnumTypeMismatch();

    $findings = iterator_to_array((new SchemaEnumTypeMismatch())->checkField($field, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when a string enum contains an int', function (): void {
    $field = makeFieldWithEnum('Color', 'string', ['red', 42]);
    $context = makeContextForEnumTypeMismatch();

    $findings = iterator_to_array((new SchemaEnumTypeMismatch())->checkField($field, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('string')
        ->and($findings[0]->message)->toContain('index 1');
});

it('accepts both int and float for number type', function (): void {
    $field = makeFieldWithEnum('Score', 'number', [1, 2.5, 3]);
    $context = makeContextForEnumTypeMismatch();

    $findings = iterator_to_array((new SchemaEnumTypeMismatch())->checkField($field, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when a number enum contains a string', function (): void {
    $field = makeFieldWithEnum('Score', 'number', [1.5, 'high']);
    $context = makeContextForEnumTypeMismatch();

    $findings = iterator_to_array((new SchemaEnumTypeMismatch())->checkField($field, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('number');
});

it('emits no finding when all boolean enum values are bools', function (): void {
    $field = makeFieldWithEnum('Toggle', 'boolean', [true, false]);
    $context = makeContextForEnumTypeMismatch();

    $findings = iterator_to_array((new SchemaEnumTypeMismatch())->checkField($field, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when a boolean enum contains an int', function (): void {
    $field = makeFieldWithEnum('Toggle', 'boolean', [true, 0]);
    $context = makeContextForEnumTypeMismatch();

    $findings = iterator_to_array((new SchemaEnumTypeMismatch())->checkField($field, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('boolean');
});

it('skips fields with no type declared', function (): void {
    $field = makeFieldWithEnum('Untyped', null, ['a', 1, true]);
    $context = makeContextForEnumTypeMismatch();

    $findings = iterator_to_array((new SchemaEnumTypeMismatch())->checkField($field, $context));

    expect($findings)->toBe([]);
});

it('skips fields with an unsupported type', function (): void {
    $field = makeFieldWithEnum('Things', 'array', ['a', 'b']);
    $context = makeContextForEnumTypeMismatch();

    $findings = iterator_to_array((new SchemaEnumTypeMismatch())->checkField($field, $context));

    expect($findings)->toBe([]);
});

it('emits multiple findings for multiple mismatched values', function (): void {
    $field = makeFieldWithEnum('Mixed', 'integer', ['one', 2, 'three']);
    $context = makeContextForEnumTypeMismatch();

    $findings = iterator_to_array((new SchemaEnumTypeMismatch())->checkField($field, $context));

    expect($findings)->toHaveCount(2);
});
