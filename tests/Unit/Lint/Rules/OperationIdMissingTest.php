<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\OperationIdMissing;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new OperationIdMissing();

    expect($rule->id())->toBe('operation.id-missing')
        ->and($rule->level())->toBe(1);
});

it('emits no finding when the operation has an operationId', function (): void {
    $rule = new OperationIdMissing();
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/users',
        method: 'GET',
        operationId: 'users.index',
    );

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits a finding when an operation has no operationId', function (): void {
    $rule = new OperationIdMissing();
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/users',
        method: 'GET',
        operationId: null,
    );

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.id-missing')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('GET')
        ->and($findings[0]->message)->toContain('/users')
        ->and($findings[0]->message)->toContain('no operationId');
});

it('emits a finding per operation missing an operationId', function (): void {
    $rule = new OperationIdMissing();
    $context = OperationNodeFactory::emptyContext();

    $op1 = OperationNodeFactory::makeOperation(pathUri: '/users', method: 'GET', operationId: null);
    $op2 = OperationNodeFactory::makeOperation(pathUri: '/posts', method: 'POST', operationId: null);

    $findings = [
        ...iterator_to_array($rule->checkOperation($op1, $context)),
        ...iterator_to_array($rule->checkOperation($op2, $context)),
    ];

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('GET')
        ->and($findings[0]->message)->toContain('/users')
        ->and($findings[1]->message)->toContain('POST')
        ->and($findings[1]->message)->toContain('/posts');
});

it('does not flag operations that have an operationId alongside missing ones', function (): void {
    $rule = new OperationIdMissing();
    $context = OperationNodeFactory::emptyContext();

    $opWithId = OperationNodeFactory::makeOperation(pathUri: '/users', method: 'GET', operationId: 'users.index');
    $opWithoutId = OperationNodeFactory::makeOperation(pathUri: '/users', method: 'POST', operationId: null);

    $findings = [
        ...iterator_to_array($rule->checkOperation($opWithId, $context)),
        ...iterator_to_array($rule->checkOperation($opWithoutId, $context)),
    ];

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('POST');
});
