<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\Rules\OperationIdDuplicate;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new OperationIdDuplicate();

    expect($rule->id())
        ->toBe('operation.id-duplicate')
        ->and($rule->level())->toBe(0);
});

it('emits no finding when all operationIds are unique', function (): void {
    $rule = new OperationIdDuplicate();
    $context = OperationNodeFactory::emptyContext();

    foreach (['users.index', 'users.show', 'posts.index'] as $index => $operationId) {
        $operation = OperationNodeFactory::makeOperation(
            pathUri: '/path-' . $index,
            operationId: $operationId,
        );
        iterator_to_array($rule->checkOperation($operation, $context));
    }

    expect(iterator_to_array($rule->finalize($context)))->toBe([]);
});

it('emits findings for duplicate operationIds', function (): void {
    $rule = new OperationIdDuplicate();
    $context = OperationNodeFactory::emptyContext();

    foreach (['users.index', 'users.index'] as $index => $operationId) {
        $operation = OperationNodeFactory::makeOperation(
            pathUri: '/path-' . $index,
            operationId: $operationId,
        );
        iterator_to_array($rule->checkOperation($operation, $context));
    }

    $findings = iterator_to_array($rule->finalize($context));

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->ruleId)->toBe('operation.id-duplicate')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('users.index')
        ->and($findings[0]->message)->toContain('2 occurrences')
        ->and($findings[1]->message)->toContain('users.index');
});

it('emits no finding when operations have no operationId', function (): void {
    $rule = new OperationIdDuplicate();
    $context = OperationNodeFactory::emptyContext();

    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/foo',
        operationId: null,
    );

    iterator_to_array($rule->checkOperation($operation, $context));

    expect(iterator_to_array($rule->finalize($context)))->toBe([]);
});

it('emits no finding when no operations are visited', function (): void {
    $rule = new OperationIdDuplicate();
    $context = OperationNodeFactory::emptyContext();

    expect(iterator_to_array($rule->finalize($context)))->toBe([]);
});

it('reports the correct path and method for each duplicate occurrence', function (): void {
    $rule = new OperationIdDuplicate();
    $context = OperationNodeFactory::emptyContext();

    $op1 = OperationNodeFactory::makeOperation(pathUri: '/alpha', operationId: 'duplicate.id');
    $op2 = OperationNodeFactory::makeOperation(
        pathUri: '/beta',
        method: HttpMethod::Post,
        operationId: 'duplicate.id',
    );

    iterator_to_array($rule->checkOperation($op1, $context));
    iterator_to_array($rule->checkOperation($op2, $context));

    $findings = iterator_to_array($rule->finalize($context));

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->location->routeUri)->toBe('/alpha')
        ->and($findings[0]->location->routeMethod)->toBe(HttpMethod::Get)
        ->and($findings[1]->location->routeUri)->toBe('/beta')
        ->and($findings[1]->location->routeMethod)->toBe(HttpMethod::Post);
});
