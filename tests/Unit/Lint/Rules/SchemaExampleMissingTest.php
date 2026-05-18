<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\SchemaExampleMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

function makeComponentSchemaNodeForExampleMissing(
    string $schemaName,
    mixed $example = null,
    bool $omitExample = false,
    ?array $examples = null,
    bool $omitExamples = false,
    ?array $enum = null,
): ComponentSchemaNode {
    $ctx = new Context();

    $schemaProps = [
        'schema' => $schemaName,
        'type' => 'string',
        '_context' => $ctx,
    ];

    if (!$omitExample) {
        $schemaProps['example'] = $example;
    }

    if (!$omitExamples && $examples !== null) {
        $schemaProps['examples'] = $examples;
    }

    if ($enum !== null) {
        $schemaProps['enum'] = $enum;
    }

    $schema = new OA\Schema($schemaProps);

    return new ComponentSchemaNode(
        name: $schemaName,
        description: null,
        fields: [],
        raw: $schema,
    );
}

function makeContextForExampleMissingTest(): LintContext
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

it('has the correct rule id and level', function (): void {
    $rule = new SchemaExampleMissing();

    expect($rule->id())->toBe('schema.example-missing')
        ->and($rule->level())->toBe(4);
});

it('emits a finding when a schema has no example', function (): void {
    $rule = new SchemaExampleMissing();
    $component = makeComponentSchemaNodeForExampleMissing('UserName', omitExample: true);
    $ctx = makeContextForExampleMissingTest();

    $findings = iterator_to_array($rule->checkComponentSchema($component, $ctx));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.example-missing')
        ->and($findings[0]->level)->toBe(4)
        ->and($findings[0]->message)->toContain('UserName')
        ->and($findings[0]->location->jsonPointer)->toBe('#/components/schemas/UserName');
});

it('emits a finding when a schema has a null example', function (): void {
    $rule = new SchemaExampleMissing();
    $component = makeComponentSchemaNodeForExampleMissing('UserName', example: null);
    $ctx = makeContextForExampleMissingTest();

    $findings = iterator_to_array($rule->checkComponentSchema($component, $ctx));

    expect($findings)->toHaveCount(1);
});

it('emits no findings when a schema has an example', function (): void {
    $rule = new SchemaExampleMissing();
    $component = makeComponentSchemaNodeForExampleMissing('UserName', example: 'John Doe');
    $ctx = makeContextForExampleMissingTest();

    $findings = iterator_to_array($rule->checkComponentSchema($component, $ctx));

    expect($findings)->toBe([]);
});

it('emits no findings when a schema has an enum', function (): void {
    $rule = new SchemaExampleMissing();
    $component = makeComponentSchemaNodeForExampleMissing(
        'Status',
        omitExample: true,
        enum: ['active', 'archived'],
    );
    $ctx = makeContextForExampleMissingTest();

    $findings = iterator_to_array($rule->checkComponentSchema($component, $ctx));

    expect($findings)->toBe([]);
});

it('emits no findings when a schema has examples (plural)', function (): void {
    $rule = new SchemaExampleMissing();
    $component = makeComponentSchemaNodeForExampleMissing(
        'UserName',
        omitExample: true,
        examples: ['John Doe', 'Jane Doe'],
    );
    $ctx = makeContextForExampleMissingTest();

    $findings = iterator_to_array($rule->checkComponentSchema($component, $ctx));

    expect($findings)->toBe([]);
});
