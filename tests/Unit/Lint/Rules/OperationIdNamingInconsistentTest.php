<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\IdentifierCase;
use Radiergummi\OpenApi\Lint\Rules\OperationIdNamingInconsistent;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new OperationIdNamingInconsistent();

    expect($rule->id)->toBe('operation.id-naming-inconsistent')
        ->and($rule->severity)->toBe(Severity::Inconsistent);
});

it('emits no finding for a permitted dot-separated operationId', function (string $operationId, string $path): void {
    $rule = new OperationIdNamingInconsistent();
    $operation = OperationNodeFactory::makeOperation(pathUri: $path, operationId: $operationId);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    'multi-segment'        => ['api.v0.users.index', '/users'],
    'single-segment'       => ['users', '/users'],
    'with numeric segment' => ['api.v0.users.index', '/api/v0/users'],
]);

it('emits a finding for an inconsistent operationId under the default (dot) case', function (string $operationId): void {
    $rule = new OperationIdNamingInconsistent();
    $operation = OperationNodeFactory::makeOperation(pathUri: '/users', operationId: $operationId);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.id-naming-inconsistent')
        ->and($findings[0]->severity)->toBe(Severity::Inconsistent)
        ->and($findings[0]->message)->toContain($operationId);
})->with([
    'camelCase'                  => ['getUsers'],
    'snake_case'                 => ['get_users'],
    'starts with uppercase'      => ['Users.index'],
]);

it('skips operations without an operationId', function (): void {
    $rule = new OperationIdNamingInconsistent();
    $operation = OperationNodeFactory::makeOperation(pathUri: '/users', operationId: null);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('kebab case: passes a valid kebab operationId', function (): void {
    $rule = new OperationIdNamingInconsistent(IdentifierCase::Kebab);
    $operation = OperationNodeFactory::makeOperation(pathUri: '/projects', operationId: 'api-v0-projects');

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('kebab case: flags a dot-separated operationId as inconsistent', function (): void {
    $rule = new OperationIdNamingInconsistent(IdentifierCase::Kebab);
    $operation = OperationNodeFactory::makeOperation(pathUri: '/projects', operationId: 'api.v0.projects');

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.id-naming-inconsistent')
        ->and($findings[0]->message)->toContain('api.v0.projects')
        ->and($findings[0]->message)->toContain('kebab-case');
});

it('default (dot) case flags a snake_case operationId with the expected hint', function (): void {
    $rule = new OperationIdNamingInconsistent();
    $operation = OperationNodeFactory::makeOperation(pathUri: '/projects', operationId: 'api_v0_projects');

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('dot-separated lowercase');
});
