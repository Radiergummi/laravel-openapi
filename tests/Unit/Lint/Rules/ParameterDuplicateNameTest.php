<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\ParameterDuplicateName;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * @param list<string> $parameterNames
 */
function makeOperationWithParameters(array $parameterNames): OperationNode
{
    return OperationNodeFactory::makeOperation(
        pathUri: '/users',
        parameters: array_map(
            static fn(string $name) => OperationNodeFactory::makeParameter(name: $name),
            $parameterNames,
        ),
    );
}

it('reports its id and level', function (): void {
    $rule = new ParameterDuplicateName();

    expect($rule->id())->toBe('parameter.duplicate-name')
        ->and($rule->level())->toBe(0);
});

it('emits no finding when all parameters are unique', function (): void {
    $rule = new ParameterDuplicateName();
    $operation = makeOperationWithParameters(['userId', 'postId']);

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});

it('emits no finding when operation has no parameters', function (): void {
    $rule = new ParameterDuplicateName();
    $operation = makeOperationWithParameters([]);

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});

it('emits a finding when parameters share the same name', function (): void {
    $rule = new ParameterDuplicateName();
    $operation = makeOperationWithParameters(['filter', 'filter']);

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('parameter.duplicate-name')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('filter')
        ->and($findings[0]->message)->toContain('2 times');
});

it('emits one finding per duplicate group', function (): void {
    $rule = new ParameterDuplicateName();
    $operation = makeOperationWithParameters(['a', 'a', 'b', 'b']);

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('a')
        ->and($findings[1]->message)->toContain('b');
});

it('reports triple duplicates correctly', function (): void {
    $rule = new ParameterDuplicateName();
    $operation = makeOperationWithParameters(['token', 'token', 'token']);

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('3 times');
});
