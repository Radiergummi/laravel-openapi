<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\ParameterDescriptionMissing;
use Radiergummi\OpenApi\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function makeParameterForDescription(?string $description): ParameterNode
{
    return new ParameterNode(
        name: 'filter',
        required: false,
        schema: 'string',
        description: $description,
        pattern: null,
        examples: [],
        raw: null,
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new ParameterDescriptionMissing();

    expect($rule->id())->toBe('parameter.description-missing')
        ->and($rule->level())->toBe(2);
});

it('emits a finding when a parameter has a missing or blank description', function (?string $description): void {
    $rule = new ParameterDescriptionMissing();
    $parameter = makeParameterForDescription($description);

    $findings = iterator_to_array(
        $rule->checkParameter($parameter, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.description-missing')
        ->and($findings[0]->level)->toBe(2)
        ->and($findings[0]->message)->toContain('filter');
})->with([
    'null'            => [null],
    'empty string'    => [''],
    'whitespace only' => ['   '],
]);

it('emits no findings when a parameter has a description', function (): void {
    $rule = new ParameterDescriptionMissing();
    $parameter = makeParameterForDescription('Filter results by status.');

    $findings = iterator_to_array(
        $rule->checkParameter($parameter, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
