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
use Radiergummi\OpenApi\Core\Lint\Rules\SchemaConstraintsMissing;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * Build an `OA\Property` carrying only the constraint keys explicitly set. Missing keys are
 * *omitted* (not nulled) so the rule sees the same shape it would see in a real generated document.
 */
function makePropertyWithConstraints(string $propertyName, string $type, array $extras = []): OA\Property
{
    $property = new OA\Property([
        'property' => $propertyName,
        'type' => $type,
        '_context' => new Context(),
    ]);

    foreach ($extras as $key => $value) {
        $property->{$key} = $value;
    }

    return $property;
}

it('has the correct rule id and level', function (): void {
    $rule = new SchemaConstraintsMissing();

    expect($rule->id())->toBe('schema.constraints-missing')
        ->and($rule->level())->toBe(4);
});

// region FieldRule

it('emits a finding for fields lacking the expected constraint', function (string $name, string $type): void {
    $rule = new SchemaConstraintsMissing();
    $field = OperationNodeFactory::makeField(
        name: $name,
        type: $type,
        raw: makePropertyWithConstraints($name, $type),
    );

    $findings = iterator_to_array($rule->checkField($field, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.constraints-missing')
        ->and($findings[0]->level)->toBe(4)
        ->and($findings[0]->message)->toContain($name);
})->with([
    'string without maxLength'        => ['name', 'string'],
    'array without maxItems'          => ['tags', 'array'],
    'integer without min/max bounds'  => ['count', 'integer'],
]);

it('emits no finding when a constraint or escape hatch is present', function (string $name, string $type, ?string $format, ?array $enum, array $rawExtras): void {
    $rule = new SchemaConstraintsMissing();
    $field = OperationNodeFactory::makeField(
        name: $name,
        type: $type,
        format: $format,
        enum: $enum,
        raw: makePropertyWithConstraints($name, $type, $rawExtras),
    );

    $findings = iterator_to_array($rule->checkField($field, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
})->with([
    'string with maxLength'   => ['name', 'string', null, null, ['maxLength' => 255]],
    'string with format'      => ['createdAt', 'string', 'date-time', null, ['format' => 'date-time']],
    'string with enum'        => ['status', 'string', null, ['active', 'archived'], ['enum' => ['active', 'archived']]],
    'array with maxItems'     => ['tags', 'array', null, null, ['maxItems' => 20]],
    'integer with minimum'    => ['count', 'integer', null, null, ['minimum' => 0]],
    'unconstrained boolean'   => ['active', 'boolean', null, null, []],
]);

it('emits no finding for a field with null raw (no OA\\Property available)', function (): void {
    $rule = new SchemaConstraintsMissing();
    $field = OperationNodeFactory::makeField(name: 'name', type: 'string');

    $findings = iterator_to_array($rule->checkField($field, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});

// endregion

// region ComponentSchemaRule

it('emits a finding via checkComponentSchema for a string schema with no maxLength', function (): void {
    $rule = new SchemaConstraintsMissing();
    $schema = new OA\Schema([
        'schema' => 'ShortName',
        'type' => 'string',
        '_context' => new Context(),
    ]);
    $node = OperationNodeFactory::makeComponentSchema(name: 'ShortName', raw: $schema);

    $findings = iterator_to_array($rule->checkComponentSchema($node, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.constraints-missing')
        ->and($findings[0]->message)->toContain('ShortName');
});

it('emits no finding via checkComponentSchema when raw is null', function (): void {
    $rule = new SchemaConstraintsMissing();
    $node = OperationNodeFactory::makeComponentSchema(name: 'NoRaw');

    $findings = iterator_to_array($rule->checkComponentSchema($node, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});

it('emits no finding via checkComponentSchema for a string schema with maxLength', function (): void {
    $rule = new SchemaConstraintsMissing();
    $schema = new OA\Schema([
        'schema' => 'ShortName',
        'type' => 'string',
        '_context' => new Context(),
    ]);
    $schema->maxLength = 100;
    $node = OperationNodeFactory::makeComponentSchema(name: 'ShortName', raw: $schema);

    $findings = iterator_to_array($rule->checkComponentSchema($node, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});

// endregion
