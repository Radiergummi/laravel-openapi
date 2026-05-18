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
use Radiergummi\OpenApi\Core\Lint\Rules\EnumValuesUndocumented;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

/**
 * @param list<mixed> $enumValues
 */
function makeFieldForEnumValuesUndocumented(
    string $name,
    array $enumValues,
    ?string $description = null,
): FieldNode {
    return new FieldNode(
        name: $name,
        type: 'string',
        required: false,
        nullable: false,
        description: $description,
        format: null,
        example: null,
        enum: $enumValues,
        children: [],
        examples: [],
        ref: null,
        raw: null,
    );
}

function makeContextForEnumValuesUndocumented(): LintContext
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

it('has the correct rule id and level', function (): void {
    $rule = new EnumValuesUndocumented();

    expect($rule->id())->toBe('enum.values-undocumented')
        ->and($rule->level())->toBe(2);
});

it('emits a finding when an enum field has no description', function (): void {
    $rule = new EnumValuesUndocumented();
    $field = makeFieldForEnumValuesUndocumented(
        'Status',
        ['active', 'inactive'],
        description: null,
    );
    $context = makeContextForEnumValuesUndocumented();

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('enum.values-undocumented')
        ->and($findings[0]->level)->toBe(2)
        ->and($findings[0]->message)->toContain('Status');
});

it('emits a finding when description does not mention any enum values', function (): void {
    $rule = new EnumValuesUndocumented();
    $field = makeFieldForEnumValuesUndocumented(
        'Status',
        ['active', 'inactive'],
        description: 'The current state of the entity.',
    );
    $context = makeContextForEnumValuesUndocumented();

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('does not mention');
});

it('emits no findings when description mentions an enum value', function (): void {
    $rule = new EnumValuesUndocumented();
    $field = makeFieldForEnumValuesUndocumented(
        'Status',
        ['active', 'inactive'],
        description: 'Can be active or inactive.',
    );
    $context = makeContextForEnumValuesUndocumented();

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toBe([]);
});

it('emits no findings when description contains a bullet list', function (): void {
    $rule = new EnumValuesUndocumented();
    $field = makeFieldForEnumValuesUndocumented(
        'Status',
        ['active', 'inactive'],
        description: "Possible statuses:\n- First option\n- Second option",
    );
    $context = makeContextForEnumValuesUndocumented();

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toBe([]);
});

it('emits no findings when description contains an asterisk list', function (): void {
    $rule = new EnumValuesUndocumented();
    $field = makeFieldForEnumValuesUndocumented(
        'Status',
        ['active', 'inactive'],
        description: "Possible statuses:\n* First option\n* Second option",
    );
    $context = makeContextForEnumValuesUndocumented();

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toBe([]);
});

it('skips fields without enum values', function (): void {
    $rule = new EnumValuesUndocumented();
    $field = new FieldNode(
        name: 'PlainString',
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
        raw: null,
    );
    $context = makeContextForEnumValuesUndocumented();

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toBe([]);
});
