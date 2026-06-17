<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\AddOperationIdFixer;
use Radiergummi\OpenApi\Lint\Rules\OperationIdMissing;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix\MissingOperationIdFixtureController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new OperationIdMissing();

    expect($rule->id())
        ->toBe('operation.id-missing')
        ->and($rule->severity())->toBe(Severity::Degraded);
});

it('emits no finding when the operation has an operationId', function (): void {
    $rule = new OperationIdMissing();
    $operation = OperationNodeFactory::makeOperation(pathUri: '/users', operationId: 'users.index');

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits a finding when an operation has no operationId', function (): void {
    $rule = new OperationIdMissing();
    $operation = OperationNodeFactory::makeOperation(pathUri: '/users', operationId: null);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.id-missing')
        ->and($findings[0]->severity)->toBe(Severity::Degraded)
        ->and($findings[0]->message)->toContain('GET')
        ->and($findings[0]->message)->toContain('/users')
        ->and($findings[0]->message)->toContain('no operationId');
});

it('emits a finding per operation missing an operationId', function (): void {
    $rule = new OperationIdMissing();
    $context = OperationNodeFactory::emptyContext();

    $op1 = OperationNodeFactory::makeOperation(pathUri: '/users', operationId: null);
    $op2 = OperationNodeFactory::makeOperation(pathUri: '/posts', method: HttpMethod::Post, operationId: null);

    $findings = [
        ...iterator_to_array($rule->checkOperation($op1, $context)),
        ...iterator_to_array($rule->checkOperation($op2, $context)),
    ];

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->message)->toContain('GET')
        ->and($findings[0]->message)->toContain('/users')
        ->and($findings[1]->message)->toContain('POST')
        ->and($findings[1]->message)->toContain('/posts');
});

it('does not flag operations that have an operationId alongside missing ones', function (): void {
    $rule = new OperationIdMissing();
    $context = OperationNodeFactory::emptyContext();

    $opWithId = OperationNodeFactory::makeOperation(pathUri: '/users', operationId: 'users.index');
    $opWithoutId = OperationNodeFactory::makeOperation(pathUri: '/users', method: HttpMethod::Post, operationId: null);

    $findings = [
        ...iterator_to_array($rule->checkOperation($opWithId, $context)),
        ...iterator_to_array($rule->checkOperation($opWithoutId, $context)),
    ];

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->message)->toContain('POST');
});

it('stamps the derived operationId and source member onto the finding when a descriptor is present', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(
        MissingOperationIdFixtureController::class,
        'withoutAttribute',
    );
    $operation = OperationNodeFactory::forDescriptor($descriptor, operationId: null);

    $findings = iterator_to_array(
        (new OperationIdMissing())->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    // The factory's route is the unnamed GET /x, so the route-name strategy falls back to {method}_{path}.
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->context[Finding::CONTEXT_SOURCE_CLASS])->toBe(MissingOperationIdFixtureController::class)
        ->and($findings[0]->context[Finding::CONTEXT_SOURCE_MEMBER])->toBe('withoutAttribute')
        ->and($findings[0]->context[AddOperationIdFixer::CONTEXT_OPERATION_ID])->toBe('get_x');
});

it('stamps no fix context when the operation has no descriptor', function (): void {
    $operation = OperationNodeFactory::makeOperation(pathUri: '/users', operationId: null);

    $findings = iterator_to_array(
        (new OperationIdMissing())->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->context)->toBe([]);
});
