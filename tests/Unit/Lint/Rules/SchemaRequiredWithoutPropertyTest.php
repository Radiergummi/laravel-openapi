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
use Radiergummi\OpenApi\Core\Lint\Rules\SchemaRequiredWithoutProperty;
use Radiergummi\OpenApi\Core\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * Build a `ComponentSchemaNode` whose raw schema declares the given `required`
 * list and properties. Fields are mirrored into the tree node so the rule sees
 * the same property names on both sides.
 *
 * @param list<string>      $properties Property names to add as fields and raw OA\Property entries
 * @param null|list<string> $required   Required property names, or null to omit the key entirely
 */
function makeComponentForRequiredProps(string $schemaName, array $properties, ?array $required): ComponentSchemaNode
{
    $ctx = new Context();

    $oaProperties = [];
    $fields = [];

    foreach ($properties as $propName) {
        $oaProperties[] = new OA\Property(['property' => $propName, 'type' => 'string', '_context' => $ctx]);
        $fields[] = OperationNodeFactory::makeField(name: $propName, required: true);
    }

    $schemaProps = ['schema' => $schemaName, '_context' => $ctx];

    if ($oaProperties !== []) {
        $schemaProps['properties'] = $oaProperties;
    }

    if ($required !== null) {
        $schemaProps['required'] = $required;
    }

    return OperationNodeFactory::makeComponentSchema(
        name: $schemaName,
        fields: $fields,
        raw: new OA\Schema($schemaProps),
    );
}

it('reports its id and level', function (): void {
    $rule = new SchemaRequiredWithoutProperty();

    expect($rule->id())->toBe('schema.required-without-property')
        ->and($rule->level())->toBe(0);
});

it('emits no finding when all required properties exist', function (): void {
    $component = makeComponentForRequiredProps('User', ['name', 'email'], ['name', 'email']);

    $findings = iterator_to_array(
        new SchemaRequiredWithoutProperty()->checkComponentSchema($component, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits a finding when a required property does not exist', function (): void {
    $component = makeComponentForRequiredProps('User', ['name'], ['name', 'email']);

    $findings = iterator_to_array(
        new SchemaRequiredWithoutProperty()->checkComponentSchema($component, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.required-without-property')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('User')
        ->and($findings[0]->message)->toContain('email')
        ->and($findings[0]->location->jsonPointer)->toBe('#/components/schemas/User/required');
});

it('emits a finding per missing required property', function (): void {
    $component = makeComponentForRequiredProps('Order', ['id'], ['id', 'total', 'currency']);

    $findings = iterator_to_array(
        new SchemaRequiredWithoutProperty()->checkComponentSchema($component, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('total')
        ->and($findings[1]->message)->toContain('currency');
});

it('emits no finding when schema has no required list', function (): void {
    $component = makeComponentForRequiredProps('Simple', ['name'], required: null);

    $findings = iterator_to_array(
        new SchemaRequiredWithoutProperty()->checkComponentSchema($component, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits a finding when schema has required but no properties at all', function (): void {
    $component = makeComponentForRequiredProps('Empty', [], ['phantom']);

    $findings = iterator_to_array(
        new SchemaRequiredWithoutProperty()->checkComponentSchema($component, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('phantom');
});
