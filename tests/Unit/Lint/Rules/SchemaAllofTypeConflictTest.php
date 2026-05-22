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
use Radiergummi\OpenApi\Core\Lint\Rules\SchemaAllOfTypeConflict;
use Radiergummi\OpenApi\Core\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * Build a `ComponentSchemaNode` whose raw schema is an `allOf` of sub-schemas
 * with the given types. A null entry means "sub-schema without a type" (used
 * to verify the rule ignores untyped slots).
 *
 * @param list<null|string> $types
 */
function makeAllOfComponent(string $schemaName, array $types): ComponentSchemaNode
{
    $ctx = new Context();
    $subSchemas = [];

    foreach ($types as $type) {
        $props = ['_context' => $ctx];

        if ($type !== null) {
            $props['type'] = $type;
        }

        $subSchemas[] = new OA\Schema($props);
    }

    return OperationNodeFactory::makeComponentSchema(
        name: $schemaName,
        raw: new OA\Schema([
            'schema' => $schemaName,
            'allOf' => $subSchemas,
            '_context' => $ctx,
        ]),
    );
}

it('reports its id and level', function (): void {
    $rule = new SchemaAllOfTypeConflict();

    expect($rule->id())->toBe('schema.allof-type-conflict')
        ->and($rule->level())->toBe(1);
});

it('emits no finding when allOf types do not conflict', function (string $label, array $types): void {
    $component = makeAllOfComponent($label, $types);

    $findings = iterator_to_array(
        new SchemaAllOfTypeConflict()->checkComponentSchema($component, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    'same type'          => ['Combined', ['object', 'object']],
    'one typed sub'      => ['Single', ['object', null]],
]);

it('emits no finding when schema has no allOf', function (): void {
    $schema = new OA\Schema([
        'schema' => 'Simple',
        'type' => 'object',
        '_context' => new Context(),
    ]);
    $component = OperationNodeFactory::makeComponentSchema(name: 'Simple', raw: $schema);

    $findings = iterator_to_array(
        new SchemaAllOfTypeConflict()->checkComponentSchema($component, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits a finding when allOf sub-schemas have conflicting types', function (): void {
    $component = makeAllOfComponent('Conflict', ['string', 'integer']);

    $findings = iterator_to_array(
        new SchemaAllOfTypeConflict()->checkComponentSchema($component, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.allof-type-conflict')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('Conflict')
        ->and($findings[0]->message)->toContain('string')
        ->and($findings[0]->message)->toContain('integer')
        ->and($findings[0]->location->jsonPointer)->toBe('#/components/schemas/Conflict/allOf');
});

it('detects conflicts across three sub-schemas', function (): void {
    $component = makeAllOfComponent('Triple', ['object', 'string', 'integer']);

    $findings = iterator_to_array(
        new SchemaAllOfTypeConflict()->checkComponentSchema($component, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('object')
        ->and($findings[0]->message)->toContain('string')
        ->and($findings[0]->message)->toContain('integer');
});
