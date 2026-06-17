<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\DiscriminatorInvalidMapping;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Lint\TreeIndex;

uses()->group('openapi', 'lint');

/**
 * Drive `DiscriminatorInvalidMapping` against a spec built from a base schema
 * with a discriminator + the named child schemas. Returns all findings emitted
 * across the per-component walk plus `finalize()`.
 *
 * @param array<string, string>       $mapping discriminator value → $ref
 * @param array<string, list<string>> $schemas schema name → list of property names
 */
function discriminatorInvalidMappingFindings(string $propertyName, array $mapping, array $schemas): array
{
    $context = new Context();
    $oaSchemas = [];
    $componentNodes = [];

    foreach ($schemas as $name => $properties) {
        $oaSchema = new OA\Schema([
            'schema' => $name,
            'properties' => array_map(
                static fn(string $propName) => new OA\Property(['property' => $propName, 'type' => 'string', '_context' => $context]),
                $properties,
            ),
            '_context' => $context,
        ]);

        $oaSchemas[] = $oaSchema;
        $componentNodes[] = new ComponentSchemaNode(name: $name, description: null, fields: [], raw: $oaSchema);
    }

    $baseSchema = new OA\Schema([
        'schema' => 'Base',
        'discriminator' => new OA\Discriminator([
            'propertyName' => $propertyName,
            'mapping' => $mapping,
            '_context' => $context,
        ]),
        '_context' => $context,
    ]);

    $oaSchemas[] = $baseSchema;
    $componentNodes[] = new ComponentSchemaNode(name: 'Base', description: null, fields: [], raw: $baseSchema);

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $context]),
        'components' => new OA\Components(['schemas' => $oaSchemas, '_context' => $context]),
    ]);

    $lintCtx = new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );

    $rule = new DiscriminatorInvalidMapping();

    foreach ($componentNodes as $component) {
        iterator_to_array($rule->checkComponentSchema($component, $lintCtx));
    }

    return iterator_to_array($rule->finalize($lintCtx));
}

it('reports its id and level', function (): void {
    $rule = new DiscriminatorInvalidMapping();

    expect($rule->id())->toBe('discriminator.invalid-mapping')
        ->and($rule->severity())->toBe(Severity::Broken);
});

it('emits no finding when all mapped schemas declare the discriminator property', function (): void {
    $findings = discriminatorInvalidMappingFindings(
        propertyName: 'type',
        mapping: ['dog' => '#/components/schemas/Dog', 'cat' => '#/components/schemas/Cat'],
        schemas: ['Dog' => ['type'], 'Cat' => ['type']],
    );

    expect($findings)->toBe([]);
});

it('emits a finding when a mapped schema does not declare the discriminator property', function (): void {
    $findings = discriminatorInvalidMappingFindings(
        propertyName: 'type',
        mapping: ['dog' => '#/components/schemas/Dog'],
        schemas: ['Dog' => ['name']], // missing 'type'
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('discriminator.invalid-mapping')
        ->and($findings[0]->severity)->toBe(Severity::Broken)
        ->and($findings[0]->message)->toContain('Dog')
        ->and($findings[0]->message)->toContain('type');
});

it('resolves a bare schema-name mapping value (no $ref prefix)', function (): void {
    // OpenAPI permits a discriminator mapping value to be a plain component name
    // instead of a full `#/components/schemas/…` ref; that fallback must still resolve.
    $findings = discriminatorInvalidMappingFindings(
        propertyName: 'type',
        mapping: ['dog' => 'Dog'],
        schemas: ['Dog' => ['name']], // missing 'type'
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('Dog')
        ->and($findings[0]->message)->toContain('type');
});

it('emits a finding when a mapped schema does not exist', function (): void {
    $findings = discriminatorInvalidMappingFindings(
        propertyName: 'type',
        mapping: ['ghost' => '#/components/schemas/Ghost'],
        schemas: [],
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('Ghost')
        ->and($findings[0]->message)->toContain('unknown schema');
});

it('emits a finding per invalid mapping entry', function (): void {
    $findings = discriminatorInvalidMappingFindings(
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

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('Beta')
        ->and($findings[1]->message)->toContain('Gamma');
});

it('emits no finding when the discriminator property is inherited via allOf', function (): void {
    $context = new Context();

    // BaseAnimal declares the discriminator property 'type' directly
    $baseAnimal = new OA\Schema([
        'schema' => 'BaseAnimal',
        'properties' => [new OA\Property(['property' => 'type', 'type' => 'string', '_context' => $context])],
        '_context' => $context,
    ]);

    // Dog inherits 'type' via allOf — does NOT redeclare directly
    $dog = new OA\Schema([
        'schema' => 'Dog',
        'allOf' => [new OA\Schema(['ref' => '#/components/schemas/BaseAnimal', '_context' => $context])],
        '_context' => $context,
    ]);

    $baseSchema = new OA\Schema([
        'schema' => 'Pet',
        'discriminator' => new OA\Discriminator([
            'propertyName' => 'type',
            'mapping' => ['dog' => '#/components/schemas/Dog'],
            '_context' => $context,
        ]),
        '_context' => $context,
    ]);

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $context]),
        'components' => new OA\Components(['schemas' => [$baseAnimal, $dog, $baseSchema], '_context' => $context]),
    ]);

    $component = new ComponentSchemaNode(name: 'Pet', description: null, fields: [], raw: $baseSchema);
    $lintCtx = new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );

    $findings = iterator_to_array(new DiscriminatorInvalidMapping()->checkComponentSchema($component, $lintCtx));

    expect($findings)->toBe([]);
});

it('emits no finding when there is no discriminator', function (): void {
    $context = new Context();

    $schema = new OA\Schema([
        'schema' => 'Simple',
        'properties' => [new OA\Property(['property' => 'name', 'type' => 'string', '_context' => $context])],
        '_context' => $context,
    ]);

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $context]),
        'components' => new OA\Components(['schemas' => [$schema], '_context' => $context]),
    ]);

    $component = new ComponentSchemaNode(name: 'Simple', description: null, fields: [], raw: $schema);
    $lintCtx = new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );

    $findings = iterator_to_array(new DiscriminatorInvalidMapping()->checkComponentSchema($component, $lintCtx));

    expect($findings)->toBe([]);
});
