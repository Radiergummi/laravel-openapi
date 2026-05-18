<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\SchemaEnumEmpty;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Core\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;

uses()->group('openapi', 'lint');

function makeEnumEmptyContext(): LintContext
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

function makeFieldWithEmptyEnum(?array $enum): FieldNode
{
    return new FieldNode(
        name: 'status',
        type: 'string',
        required: false,
        nullable: false,
        description: null,
        format: null,
        example: null,
        enum: $enum,
        children: [],
        examples: [],
        ref: null,
        raw: null,
    );
}

// ---- id / level ------------------------------------------------------------

it('reports its id and level', function (): void {
    $rule = new SchemaEnumEmpty();

    expect($rule->id())->toBe('schema.enum-empty')
        ->and($rule->level())->toBe(1);
});

// ---- FieldRule — positive --------------------------------------------------

it('emits a finding for a field schema with an empty enum array', function (): void {
    $field = makeFieldWithEmptyEnum([]);
    $context = makeEnumEmptyContext();

    $findings = iterator_to_array((new SchemaEnumEmpty())->checkField($field, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.enum-empty')
        ->and($findings[0]->level)->toBe(1);
});

// ---- FieldRule — negative --------------------------------------------------

it('emits no finding for a field with a non-empty enum', function (): void {
    $field = makeFieldWithEmptyEnum(['a', 'b']);
    $context = makeEnumEmptyContext();

    $findings = iterator_to_array((new SchemaEnumEmpty())->checkField($field, $context));

    expect($findings)->toBe([]);
});

it('emits no finding for a field with no enum key (null)', function (): void {
    $field = makeFieldWithEmptyEnum(null);
    $context = makeEnumEmptyContext();

    $findings = iterator_to_array((new SchemaEnumEmpty())->checkField($field, $context));

    expect($findings)->toBe([]);
});

// ---- ComponentSchemaRule — positive ----------------------------------------

it('emits a finding for a component schema with an empty enum array', function (): void {
    $raw = new OA\Schema([]);
    $raw->enum = [];

    $node = new ComponentSchemaNode(
        name: 'Status',
        description: null,
        fields: [],
        raw: $raw,
    );

    $findings = iterator_to_array((new SchemaEnumEmpty())->checkComponentSchema($node, makeEnumEmptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.enum-empty')
        ->and($findings[0]->level)->toBe(1);
});

// ---- ComponentSchemaRule — negative ----------------------------------------

it('emits no finding for a component schema with a non-empty enum', function (): void {
    $raw = new OA\Schema([]);
    $raw->enum = ['active', 'inactive'];

    $node = new ComponentSchemaNode(
        name: 'Status',
        description: null,
        fields: [],
        raw: $raw,
    );

    $findings = iterator_to_array((new SchemaEnumEmpty())->checkComponentSchema($node, makeEnumEmptyContext()));

    expect($findings)->toBe([]);
});

it('emits no finding for a component schema without an enum key', function (): void {
    $node = new ComponentSchemaNode(
        name: 'Status',
        description: null,
        fields: [],
        raw: null,
    );

    $findings = iterator_to_array((new SchemaEnumEmpty())->checkComponentSchema($node, makeEnumEmptyContext()));

    expect($findings)->toBe([]);
});
