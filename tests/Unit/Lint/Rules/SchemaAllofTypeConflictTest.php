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
use Radiergummi\OpenApi\Core\Lint\Rules\SchemaAllOfTypeConflict;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new SchemaAllOfTypeConflict();

    expect($rule->id())->toBe('schema.allof-type-conflict')
        ->and($rule->level())->toBe(1);
});

it('emits no finding when allOf sub-schemas have the same type', function (): void {
    $component = makeAllOfComponent('Combined', ['object', 'object']);
    $ctx = makeAllOfTestContext();

    $findings = iterator_to_array((new SchemaAllOfTypeConflict())->checkComponentSchema($component, $ctx));

    expect($findings)->toBe([]);
});

it('emits no finding when only one sub-schema has a type', function (): void {
    $octx = new Context();

    $schema = new OA\Schema([
        'schema' => 'Single',
        'allOf' => [
            new OA\Schema(['type' => 'object', '_context' => $octx]),
            new OA\Schema(['_context' => $octx]),
        ],
        '_context' => $octx,
    ]);

    $component = new ComponentSchemaNode(
        name: 'Single',
        description: null,
        fields: [],
        raw: $schema,
    );

    $ctx = makeAllOfTestContext();

    $findings = iterator_to_array((new SchemaAllOfTypeConflict())->checkComponentSchema($component, $ctx));

    expect($findings)->toBe([]);
});

it('emits a finding when allOf sub-schemas have conflicting types', function (): void {
    $component = makeAllOfComponent('Conflict', ['string', 'integer']);
    $ctx = makeAllOfTestContext();

    $findings = iterator_to_array((new SchemaAllOfTypeConflict())->checkComponentSchema($component, $ctx));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.allof-type-conflict')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('Conflict')
        ->and($findings[0]->message)->toContain('string')
        ->and($findings[0]->message)->toContain('integer')
        ->and($findings[0]->location->jsonPointer)->toBe('#/components/schemas/Conflict/allOf');
});

it('emits no finding when schema has no allOf', function (): void {
    $octx = new Context();

    $schema = new OA\Schema([
        'schema' => 'Simple',
        'type' => 'object',
        '_context' => $octx,
    ]);

    $component = new ComponentSchemaNode(
        name: 'Simple',
        description: null,
        fields: [],
        raw: $schema,
    );

    $ctx = makeAllOfTestContext();

    $findings = iterator_to_array((new SchemaAllOfTypeConflict())->checkComponentSchema($component, $ctx));

    expect($findings)->toBe([]);
});

it('detects conflicts across three sub-schemas', function (): void {
    $component = makeAllOfComponent('Triple', ['object', 'string', 'integer']);
    $ctx = makeAllOfTestContext();

    $findings = iterator_to_array((new SchemaAllOfTypeConflict())->checkComponentSchema($component, $ctx));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('object')
        ->and($findings[0]->message)->toContain('string')
        ->and($findings[0]->message)->toContain('integer');
});

/**
 * Build a ComponentSchemaNode with allOf sub-schemas having the given types.
 *
 * @param list<string> $types
 */
function makeAllOfComponent(string $schemaName, array $types): ComponentSchemaNode
{
    $ctx = new Context();

    $subSchemas = [];

    foreach ($types as $type) {
        $subSchemas[] = new OA\Schema([
            'type' => $type,
            '_context' => $ctx,
        ]);
    }

    $schema = new OA\Schema([
        'schema' => $schemaName,
        'allOf' => $subSchemas,
        '_context' => $ctx,
    ]);

    return new ComponentSchemaNode(
        name: $schemaName,
        description: null,
        fields: [],
        raw: $schema,
    );
}

function makeAllOfTestContext(): LintContext
{
    $ctx = new Context();

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
    ]);

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}
