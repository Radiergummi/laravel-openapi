<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\DiscriminatorInvalidMapping;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new DiscriminatorInvalidMapping();

    expect($rule->id())->toBe('discriminator.invalid-mapping')
        ->and($rule->level())->toBe(0);
});

it('emits no finding when all mapped schemas declare the discriminator property', function (): void {
    [$ctx, , $allComponents] = makeSpecWithDiscriminatorForVisitor(
        propertyName: 'type',
        mapping: ['dog' => '#/components/schemas/Dog', 'cat' => '#/components/schemas/Cat'],
        schemas: [
            'Dog' => ['type'],
            'Cat' => ['type'],
        ],
    );

    $rule = new DiscriminatorInvalidMapping();

    foreach ($allComponents as $component) {
        iterator_to_array($rule->checkComponentSchema($component, $ctx));
    }

    $findings = iterator_to_array($rule->finalize($ctx));

    expect($findings)->toBe([]);
});

it('emits a finding when a mapped schema does not declare the discriminator property', function (): void {
    [$ctx, , $allComponents] = makeSpecWithDiscriminatorForVisitor(
        propertyName: 'type',
        mapping: ['dog' => '#/components/schemas/Dog'],
        schemas: [
            'Dog' => ['name'], // Missing 'type' property
        ],
    );

    $rule = new DiscriminatorInvalidMapping();

    foreach ($allComponents as $component) {
        iterator_to_array($rule->checkComponentSchema($component, $ctx));
    }

    $findings = iterator_to_array($rule->finalize($ctx));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('discriminator.invalid-mapping')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('Dog')
        ->and($findings[0]->message)->toContain('type');
});

it('emits a finding when a mapped schema does not exist', function (): void {
    [$ctx, , $allComponents] = makeSpecWithDiscriminatorForVisitor(
        propertyName: 'type',
        mapping: ['ghost' => '#/components/schemas/Ghost'],
        schemas: [],
    );

    $rule = new DiscriminatorInvalidMapping();

    foreach ($allComponents as $component) {
        iterator_to_array($rule->checkComponentSchema($component, $ctx));
    }

    $findings = iterator_to_array($rule->finalize($ctx));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('Ghost')
        ->and($findings[0]->message)->toContain('unknown schema');
});

it('emits a finding per invalid mapping entry', function (): void {
    [$ctx, , $allComponents] = makeSpecWithDiscriminatorForVisitor(
        propertyName: 'kind',
        mapping: [
            'a' => '#/components/schemas/Alpha',
            'b' => '#/components/schemas/Beta',
            'c' => '#/components/schemas/Gamma',
        ],
        schemas: [
            'Alpha' => ['kind'],   // valid
            'Beta' => ['name'],    // missing 'kind'
            'Gamma' => ['other'],  // missing 'kind'
        ],
    );

    $rule = new DiscriminatorInvalidMapping();

    foreach ($allComponents as $component) {
        iterator_to_array($rule->checkComponentSchema($component, $ctx));
    }

    $findings = iterator_to_array($rule->finalize($ctx));

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('Beta')
        ->and($findings[1]->message)->toContain('Gamma');
});

it('emits no finding when the discriminator property is inherited via allOf', function (): void {
    $ctx = new Context();

    // BaseAnimal declares the discriminator property 'type' directly
    $baseAnimal = new OA\Schema([
        'schema' => 'BaseAnimal',
        'properties' => [
            new OA\Property(['property' => 'type', 'type' => 'string', '_context' => $ctx]),
        ],
        '_context' => $ctx,
    ]);

    // Dog inherits from BaseAnimal via allOf — does NOT redeclare 'type' directly
    $dog = new OA\Schema([
        'schema' => 'Dog',
        'allOf' => [
            new OA\Schema(['ref' => '#/components/schemas/BaseAnimal', '_context' => $ctx]),
        ],
        '_context' => $ctx,
    ]);

    $discriminator = new OA\Discriminator([
        'propertyName' => 'type',
        'mapping' => ['dog' => '#/components/schemas/Dog'],
        '_context' => $ctx,
    ]);

    $baseSchema = new OA\Schema([
        'schema' => 'Pet',
        'discriminator' => $discriminator,
        '_context' => $ctx,
    ]);

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
        'components' => new OA\Components([
            'schemas' => [$baseAnimal, $dog, $baseSchema],
            '_context' => $ctx,
        ]),
    ]);

    $component = new ComponentSchemaNode(
        name: 'Pet',
        description: null,
        fields: [],
        raw: $baseSchema,
    );

    $lintCtx = new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );

    $findings = iterator_to_array((new DiscriminatorInvalidMapping())->checkComponentSchema($component, $lintCtx));

    expect($findings)->toBe([]);
});

it('emits no finding when there is no discriminator', function (): void {
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
        fields: [],
        raw: $schema,
    );

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
        'components' => new OA\Components([
            'schemas' => [$schema],
            '_context' => $ctx,
        ]),
    ]);

    $lintCtx = new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );

    $findings = iterator_to_array((new DiscriminatorInvalidMapping())->checkComponentSchema($component, $lintCtx));

    expect($findings)->toBe([]);
});

/**
 * Build a minimal OA\OpenApi with a base schema that has a discriminator
 * with the given mapping, plus the mapped child schemas. Returns the
 * LintContext, the base ComponentSchemaNode, and all ComponentSchemaNodes
 * (needed to feed the full index into the rule before finalize()).
 *
 * @param array<string, string>       $mapping Discriminator value → $ref
 * @param array<string, list<string>> $schemas Schema name → list of property names
 *
 * @return array{LintContext, ComponentSchemaNode, list<ComponentSchemaNode>}
 */
function makeSpecWithDiscriminatorForVisitor(
    string $propertyName,
    array $mapping,
    array $schemas,
): array {
    $ctx = new Context();

    // Build child schemas
    $oaSchemas = [];
    $componentNodes = [];

    foreach ($schemas as $name => $properties) {
        $oaProperties = [];

        foreach ($properties as $propName) {
            $oaProperties[] = new OA\Property([
                'property' => $propName,
                'type' => 'string',
                '_context' => $ctx,
            ]);
        }

        $oaSchema = new OA\Schema([
            'schema' => $name,
            'properties' => $oaProperties,
            '_context' => $ctx,
        ]);

        $oaSchemas[] = $oaSchema;
        $componentNodes[] = new ComponentSchemaNode(name: $name, description: null, fields: [], raw: $oaSchema);
    }

    // Build base schema with discriminator
    $discriminator = new OA\Discriminator([
        'propertyName' => $propertyName,
        'mapping' => $mapping,
        '_context' => $ctx,
    ]);

    $baseSchema = new OA\Schema([
        'schema' => 'Base',
        'discriminator' => $discriminator,
        '_context' => $ctx,
    ]);

    $oaSchemas[] = $baseSchema;

    $baseComponent = new ComponentSchemaNode(name: 'Base', description: null, fields: [], raw: $baseSchema);
    $componentNodes[] = $baseComponent;

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
        'components' => new OA\Components([
            'schemas' => $oaSchemas,
            '_context' => $ctx,
        ]),
    ]);

    $lintCtx = new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );

    return [$lintCtx, $baseComponent, $componentNodes];
}
