<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\PathParameterUndefined;
use Radiergummi\OpenApi\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new PathParameterUndefined();

    expect($rule->id())->toBe('path.parameter-undefined')->and($rule->severity())->toBe(Severity::Broken);
});

it('emits no finding when all path parameters match placeholders', function (): void {
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/users/{userId}',
        parameters: [OperationNodeFactory::makeParameter(name: 'userId')],
        responses: [],
    );

    $findings = iterator_to_array(
        new PathParameterUndefined()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when there are no parameters and no placeholders', function (): void {
    $operation = OperationNodeFactory::makeOperation(pathUri: '/users', parameters: [], responses: []);

    $findings = iterator_to_array(
        new PathParameterUndefined()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits a finding when a path parameter has no matching placeholder', function (): void {
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/users/{userId}',
        parameters: [
            OperationNodeFactory::makeParameter(name: 'userId'),
            OperationNodeFactory::makeParameter(name: 'orphanParam'),
        ],
        responses: [],
    );

    $findings = iterator_to_array(
        new PathParameterUndefined()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('path.parameter-undefined')
        ->and($findings[0]->severity)->toBe(Severity::Broken)
        ->and($findings[0]->message)->toContain('orphanParam');
});

it('emits findings for multiple undefined path parameters', function (): void {
    /** @var list<ParameterNode> $parameters */
    $parameters = [
        OperationNodeFactory::makeParameter(name: 'alpha'),
        OperationNodeFactory::makeParameter(name: 'beta'),
    ];
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/users',
        parameters: $parameters,
        responses: [],
    );

    $findings = iterator_to_array(
        new PathParameterUndefined()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->message)->toContain('alpha')
        ->and($findings[1]->message)->toContain('beta');
});

it('accepts a parameter paired with a Laravel optional-segment placeholder', function (string $pathUri): void {
    $operation = OperationNodeFactory::makeOperation(
        pathUri: $pathUri,
        parameters: [OperationNodeFactory::makeParameter(name: 'path')],
        responses: [],
    );

    $findings = iterator_to_array(
        new PathParameterUndefined()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    'optional only' => '/foo/{path?}',
    'static + optional' => '/.well-known/oauth-protected-resource/{path?}',
]);
