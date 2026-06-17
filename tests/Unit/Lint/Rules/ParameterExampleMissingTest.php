<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Generator;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\ParameterExampleMissing;
use Radiergummi\OpenApi\Lint\Tree\ExampleNode;
use Radiergummi\OpenApi\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * Build a `ParameterNode` named "userId" with the given `example`/`examples`
 * state on the raw `OA\Parameter`, attached to a synthetic operation.
 */
function makeUserIdParameterWithExamples(mixed $example, mixed $examples): ParameterNode
{
    $oaParam = new OA\PathParameter([
        '_context' => new Context(),
        'name' => 'userId',
    ]);
    $oaParam->example = $example;
    $oaParam->examples = $examples;

    $exampleNodes = [];

    if (is_array($examples)) {
        foreach ($examples as $ex) {
            if ($ex instanceof OA\Examples) {
                $exampleNodes[] = new ExampleNode(
                    name: $ex->example !== Generator::UNDEFINED ? (string) $ex->example : null,
                    value: $ex->value !== Generator::UNDEFINED ? $ex->value : null,
                    summary: null,
                    description: null,
                    raw: $ex,
                );
            }
        }
    }

    $param = OperationNodeFactory::makeParameter(
        name: 'userId',
        description: 'The user identifier.',
        examples: $exampleNodes,
        raw: $oaParam,
    );

    OperationNodeFactory::makeOperation(
        pathUri: '/users/{userId}',
        parameters: [$param],
    );

    return $param;
}

it('has the correct rule id and level', function (): void {
    $rule = new ParameterExampleMissing();

    expect($rule->id())->toBe('parameter.example-missing')
        ->and($rule->severity())->toBe(Severity::Improvable);
});

it('emits a finding when a parameter has neither example nor examples', function (): void {
    $rule = new ParameterExampleMissing();
    $param = makeUserIdParameterWithExamples(
        example: Generator::UNDEFINED,
        examples: Generator::UNDEFINED,
    );

    $findings = iterator_to_array($rule->checkParameter($param, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.example-missing')
        ->and($findings[0]->severity)->toBe(Severity::Improvable)
        ->and($findings[0]->message)->toContain('userId');
});

it('emits no finding when a parameter has at least one example', function (mixed $example, mixed $examples): void {
    $rule = new ParameterExampleMissing();
    $param = makeUserIdParameterWithExamples($example, $examples);

    $findings = iterator_to_array($rule->checkParameter($param, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
})->with([
    'example (singular)' => ['abc123', Generator::UNDEFINED],
    'examples (plural)' => [
        Generator::UNDEFINED,
        [new OA\Examples([
            '_context' => new Context(),
            'example' => 'user1',
            'value' => 'abc123',
        ])],
    ],
]);

it('emits no finding when raw is null (no OA\\Parameter available)', function (): void {
    $rule = new ParameterExampleMissing();
    $node = OperationNodeFactory::makeParameter(name: 'userId');

    $findings = iterator_to_array($rule->checkParameter($node, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});
