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
use Radiergummi\OpenApi\Core\Lint\Rules\SchemaRequiredWithoutProperty;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Core\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

/**
 * Build a ComponentSchemaNode with the given fields and raw schema having required.
 *
 * @param list<string> $properties Property names to add as FieldNodes
 * @param list<string> $required   Required property names for the raw schema
 */
function makeComponentForRequiredProps(
    string $schemaName,
    array $properties,
    array $required,
): ComponentSchemaNode {
    $ctx = new Context();

    $oaProperties = [];
    $fieldNodes = [];

    foreach ($properties as $propName) {
        $oaProperties[] = new OA\Property([
            'property' => $propName,
            'type' => 'string',
            '_context' => $ctx,
        ]);

        $fieldNodes[] = new FieldNode(
            name: $propName,
            type: 'string',
            required: true,
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
    }

    $schema = new OA\Schema([
        'schema' => $schemaName,
        'properties' => $oaProperties !== [] ? $oaProperties : [],
        'required' => $required,
        '_context' => $ctx,
    ]);

    return new ComponentSchemaNode(
        name: $schemaName,
        description: null,
        fields: $fieldNodes,
        raw: $schema,
    );
}

function makeContextForRequiredProps(): LintContext
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
    $rule = new SchemaRequiredWithoutProperty();

    expect($rule->id())->toBe('schema.required-without-property')
        ->and($rule->level())->toBe(0);
});

it('emits no finding when all required properties exist', function (): void {
    $component = makeComponentForRequiredProps(
        schemaName: 'User',
        properties: ['name', 'email'],
        required: ['name', 'email'],
    );
    $context = makeContextForRequiredProps();

    $findings = iterator_to_array((new SchemaRequiredWithoutProperty())->checkComponentSchema($component, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when a required property does not exist', function (): void {
    $component = makeComponentForRequiredProps(
        schemaName: 'User',
        properties: ['name'],
        required: ['name', 'email'],
    );
    $context = makeContextForRequiredProps();

    $findings = iterator_to_array((new SchemaRequiredWithoutProperty())->checkComponentSchema($component, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.required-without-property')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('User')
        ->and($findings[0]->message)->toContain('email')
        ->and($findings[0]->location->jsonPointer)->toBe('#/components/schemas/User/required');
});

it('emits a finding per missing required property', function (): void {
    $component = makeComponentForRequiredProps(
        schemaName: 'Order',
        properties: ['id'],
        required: ['id', 'total', 'currency'],
    );
    $context = makeContextForRequiredProps();

    $findings = iterator_to_array((new SchemaRequiredWithoutProperty())->checkComponentSchema($component, $context));

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('total')
        ->and($findings[1]->message)->toContain('currency');
});

it('emits no finding when schema has no required list', function (): void {
    $ctx = new Context();

    $schema = new OA\Schema([
        'schema' => 'Simple',
        'properties' => [
            new OA\Property(['property' => 'name', 'type' => 'string', '_context' => $ctx]),
        ],
        '_context' => $ctx,
    ]);

    $component = new ComponentSchemaNode(
        name: 'Simple',
        description: null,
        fields: [
            new FieldNode(
                name: 'name',
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
            ),
        ],
        raw: $schema,
    );
    $context = makeContextForRequiredProps();

    $findings = iterator_to_array((new SchemaRequiredWithoutProperty())->checkComponentSchema($component, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when schema has required but no properties at all', function (): void {
    $ctx = new Context();

    $schema = new OA\Schema([
        'schema' => 'Empty',
        'required' => ['phantom'],
        '_context' => $ctx,
    ]);

    $component = new ComponentSchemaNode(
        name: 'Empty',
        description: null,
        fields: [],
        raw: $schema,
    );
    $context = makeContextForRequiredProps();

    $findings = iterator_to_array((new SchemaRequiredWithoutProperty())->checkComponentSchema($component, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('phantom');
});
