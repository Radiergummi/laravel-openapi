<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Lint\Rules\SchemaExampleMissing;
use Radiergummi\OpenApi\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * Build a `ComponentSchemaNode` whose raw `OA\Schema` carries the requested combination of
 * `example` / `examples` / `enum` keys. Keys are only added when non-null so the rule sees the
 * same "missing key" shape it would see on a freshly built schema.
 */
function makeSchemaForExampleMissing(
    string $name,
    mixed $example = null,
    bool $omitExample = false,
    ?array $examples = null,
    ?array $enum = null,
): ComponentSchemaNode {
    $props = [
        'schema' => $name,
        'type' => 'string',
        '_context' => new Context(),
    ];

    if (!$omitExample) {
        $props['example'] = $example;
    }

    if ($examples !== null) {
        $props['examples'] = $examples;
    }

    if ($enum !== null) {
        $props['enum'] = $enum;
    }

    return OperationNodeFactory::makeComponentSchema(name: $name, raw: new OA\Schema($props));
}

it('has the correct rule id and level', function (): void {
    $rule = new SchemaExampleMissing();

    expect($rule->id())->toBe('schema.example-missing')
        ->and($rule->level())->toBe(4);
});

it('emits a finding when a schema has no example or null example', function (callable $build): void {
    $rule = new SchemaExampleMissing();
    $component = $build();

    $findings = iterator_to_array($rule->checkComponentSchema($component, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('schema.example-missing')
        ->and($findings[0]->level)->toBe(4)
        ->and($findings[0]->message)->toContain('UserName')
        ->and($findings[0]->location->jsonPointer)->toBe('#/components/schemas/UserName');
})->with([
    'no example key'  => [fn() => makeSchemaForExampleMissing('UserName', omitExample: true)],
    'null example'    => [fn() => makeSchemaForExampleMissing('UserName', example: null)],
]);

it('emits no findings when a schema has any source of example values', function (callable $build): void {
    $rule = new SchemaExampleMissing();
    $component = $build();

    $findings = iterator_to_array($rule->checkComponentSchema($component, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
})->with([
    'single example'    => [fn() => makeSchemaForExampleMissing('UserName', example: 'John Doe')],
    'plural examples'   => [fn() => makeSchemaForExampleMissing('UserName', omitExample: true, examples: ['John Doe', 'Jane Doe'])],
    'enum stands in'    => [fn() => makeSchemaForExampleMissing('Status', omitExample: true, enum: ['active', 'archived'])],
]);
