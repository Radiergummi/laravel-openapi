<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\SchemaConstraintsMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Core\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeSchemaConstraintsMissingContext(): LintContext
{
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);

    return new LintContext(
        api: new ApiNode(
            operations: [],
            components: [],
            webhooks: [],
            declaredTags: [],
            tagDescriptions: [],
            raw: $spec,
        ),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}

function makeFieldNodeForConstraints(
    string $name,
    ?string $type,
    ?string $format = null,
    ?array $enum = null,
    ?OA\Property $raw = null,
): FieldNode {
    return new FieldNode(
        name: $name,
        type: $type,
        required: false,
        nullable: false,
        description: null,
        format: $format,
        example: null,
        enum: $enum,
        children: [],
        examples: [],
        ref: null,
        raw: $raw,
    );
}

function makePropertyWithConstraints(
    string $propertyName,
    string $type,
    mixed $maxLength = null,
    mixed $maxItems = null,
    mixed $minimum = null,
    mixed $maximum = null,
    mixed $format = null,
    mixed $enum = null,
): OA\Property {
    $props = [
        'property' => $propertyName,
        'type' => $type,
        '_context' => new Context(),
    ];

    $property = new OA\Property($props);

    if ($maxLength !== null) {
        $property->maxLength = $maxLength;
    }

    if ($maxItems !== null) {
        $property->maxItems = $maxItems;
    }

    if ($minimum !== null) {
        $property->minimum = $minimum;
    }

    if ($maximum !== null) {
        $property->maximum = $maximum;
    }

    if ($format !== null) {
        $property->format = $format;
    }

    if ($enum !== null) {
        $property->enum = $enum;
    }

    return $property;
}

function makeComponentSchemaNodeForConstraints(string $name, ?OA\Schema $raw = null): ComponentSchemaNode
{
    return new ComponentSchemaNode(
        name: $name,
        description: null,
        fields: [],
        raw: $raw,
    );
}

// --- Rule identity ---

it('has the correct rule id and level', function (): void {
    $rule = new SchemaConstraintsMissing();

    expect($rule->id())->toBe('schema.constraints-missing')
        ->and($rule->level())->toBe(4);
});

// --- FieldRule: string without maxLength ---

it('emits a finding for a string field with no maxLength, format, or enum', function (): void {
    $rule = new SchemaConstraintsMissing();
    $context = makeSchemaConstraintsMissingContext();

    $raw = makePropertyWithConstraints('name', 'string');
    $field = makeFieldNodeForConstraints('name', 'string', raw: $raw);

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.constraints-missing')
        ->and($findings[0]->level)->toBe(4)
        ->and($findings[0]->message)->toContain('name');
});

it('emits no finding for a string field with maxLength set', function (): void {
    $rule = new SchemaConstraintsMissing();
    $context = makeSchemaConstraintsMissingContext();

    $raw = makePropertyWithConstraints('name', 'string', maxLength: 255);
    $field = makeFieldNodeForConstraints('name', 'string', raw: $raw);

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toBe([]);
});

it('emits no finding for a string field with a format set', function (): void {
    $rule = new SchemaConstraintsMissing();
    $context = makeSchemaConstraintsMissingContext();

    $raw = makePropertyWithConstraints('createdAt', 'string', format: 'date-time');
    $field = makeFieldNodeForConstraints('createdAt', 'string', format: 'date-time', raw: $raw);

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toBe([]);
});

it('emits no finding for a string field with an enum set', function (): void {
    $rule = new SchemaConstraintsMissing();
    $context = makeSchemaConstraintsMissingContext();

    $raw = makePropertyWithConstraints('status', 'string', enum: ['active', 'archived']);
    $field = makeFieldNodeForConstraints('status', 'string', enum: ['active', 'archived'], raw: $raw);

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toBe([]);
});

// --- FieldRule: array without maxItems ---

it('emits a finding for an array field with no maxItems', function (): void {
    $rule = new SchemaConstraintsMissing();
    $context = makeSchemaConstraintsMissingContext();

    $raw = makePropertyWithConstraints('tags', 'array');
    $field = makeFieldNodeForConstraints('tags', 'array', raw: $raw);

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('tags');
});

it('emits no finding for an array field with maxItems set', function (): void {
    $rule = new SchemaConstraintsMissing();
    $context = makeSchemaConstraintsMissingContext();

    $raw = makePropertyWithConstraints('tags', 'array', maxItems: 20);
    $field = makeFieldNodeForConstraints('tags', 'array', raw: $raw);

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toBe([]);
});

// --- FieldRule: integer/number without bounds ---

it('emits a finding for an integer field with no minimum or maximum', function (): void {
    $rule = new SchemaConstraintsMissing();
    $context = makeSchemaConstraintsMissingContext();

    $raw = makePropertyWithConstraints('count', 'integer');
    $field = makeFieldNodeForConstraints('count', 'integer', raw: $raw);

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('count');
});

it('emits no finding for an integer field with minimum set', function (): void {
    $rule = new SchemaConstraintsMissing();
    $context = makeSchemaConstraintsMissingContext();

    $raw = makePropertyWithConstraints('count', 'integer', minimum: 0);
    $field = makeFieldNodeForConstraints('count', 'integer', raw: $raw);

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toBe([]);
});

it('emits no finding for a field with null raw (no OA\\Property available)', function (): void {
    $rule = new SchemaConstraintsMissing();
    $context = makeSchemaConstraintsMissingContext();

    $field = makeFieldNodeForConstraints('name', 'string', raw: null);

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toBe([]);
});

it('emits no finding for a non-string/array/number field type', function (): void {
    $rule = new SchemaConstraintsMissing();
    $context = makeSchemaConstraintsMissingContext();

    $raw = makePropertyWithConstraints('active', 'boolean');
    $field = makeFieldNodeForConstraints('active', 'boolean', raw: $raw);

    $findings = iterator_to_array($rule->checkField($field, $context));

    expect($findings)->toBe([]);
});

// --- ComponentSchemaRule ---

it('emits a finding via checkComponentSchema for a string schema with no maxLength', function (): void {
    $rule = new SchemaConstraintsMissing();
    $context = makeSchemaConstraintsMissingContext();

    $ctx = new Context();
    $schema = new OA\Schema([
        'schema' => 'ShortName',
        'type' => 'string',
        '_context' => $ctx,
    ]);

    $node = makeComponentSchemaNodeForConstraints('ShortName', $schema);

    $findings = iterator_to_array($rule->checkComponentSchema($node, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.constraints-missing')
        ->and($findings[0]->message)->toContain('ShortName');
});

it('emits no finding via checkComponentSchema when raw is null', function (): void {
    $rule = new SchemaConstraintsMissing();
    $context = makeSchemaConstraintsMissingContext();

    $node = makeComponentSchemaNodeForConstraints('NoRaw', null);

    $findings = iterator_to_array($rule->checkComponentSchema($node, $context));

    expect($findings)->toBe([]);
});

it('emits no finding via checkComponentSchema for a string schema with maxLength', function (): void {
    $rule = new SchemaConstraintsMissing();
    $context = makeSchemaConstraintsMissingContext();

    $ctx = new Context();
    $schema = new OA\Schema([
        'schema' => 'ShortName',
        'type' => 'string',
        '_context' => $ctx,
    ]);
    $schema->maxLength = 100;

    $node = makeComponentSchemaNodeForConstraints('ShortName', $schema);

    $findings = iterator_to_array($rule->checkComponentSchema($node, $context));

    expect($findings)->toBe([]);
});
