<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Generator;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\ParameterExampleConflict;
use Radiergummi\OpenApi\Lint\Tree\ExampleNode;
use Radiergummi\OpenApi\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * Build a `ParameterNode` wrapping an `OA\Parameter` with the given example/examples state.
 *
 * Pass `Generator::UNDEFINED` for absent fields; pass any value for present.
 */
function makeParameterWithExamples(mixed $example, mixed $examples): ParameterNode
{
    $oaParam = new OA\PathParameter([
        '_context' => new Context(),
        'name' => 'id',
    ]);
    $oaParam->example = $example;
    $oaParam->examples = $examples;

    $exampleNodes = [];

    if (is_array($examples)) {
        foreach ($examples as $ex) {
            if ($ex instanceof OA\Examples) {
                $exampleNodes[] = new ExampleNode(
                    name: $ex->example !== Generator::UNDEFINED ? $ex->example : null,
                    value: $ex->value !== Generator::UNDEFINED ? $ex->value : null,
                    summary: null,
                    description: null,
                    raw: $ex,
                );
            }
        }
    }

    $param = OperationNodeFactory::makeParameter(
        name: 'id',
        examples: $exampleNodes,
        raw: $oaParam,
    );

    OperationNodeFactory::makeOperation(
        pathUri: '/items/{id}',
        parameters: [$param],
    );

    return $param;
}

function oaExample(string $name = 'default', string $value = '123'): OA\Examples
{
    return new OA\Examples([
        '_context' => new Context(),
        'example' => $name,
        'value' => $value,
    ]);
}

it('reports its id, level, and description', function (): void {
    $rule = new ParameterExampleConflict();

    expect($rule->id)->toBe('parameter.example-conflict')
        ->and($rule->severity)->toBe(Severity::Degraded)
        ->and($rule->description)->toBe('A parameter sets both example and examples (mutually exclusive).');
});

it('emits one finding when parameter has both example and examples set', function (): void {
    $rule = new ParameterExampleConflict();
    $node = makeParameterWithExamples(
        example: 'abc',
        examples: [oaExample()],
    );

    $findings = iterator_to_array($rule->checkParameter($node, OperationNodeFactory::emptyContext()));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.example-conflict')
        ->and($findings[0]->severity)->toBe(Severity::Degraded);
});

it('emits no finding when at most one of example/examples is set', function (mixed $example, mixed $examples): void {
    $rule = new ParameterExampleConflict();
    $node = makeParameterWithExamples($example, $examples);

    $findings = iterator_to_array($rule->checkParameter($node, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
})->with([
    'only example (singular)' => ['abc', Generator::UNDEFINED],
    'only examples (plural)' => [Generator::UNDEFINED, [oaExample()]],
    'neither set' => [Generator::UNDEFINED, Generator::UNDEFINED],
]);

it('emits no finding when raw is null (no OA\\Parameter available)', function (): void {
    $rule = new ParameterExampleConflict();
    $node = OperationNodeFactory::makeParameter(name: 'id');

    $findings = iterator_to_array($rule->checkParameter($node, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});
