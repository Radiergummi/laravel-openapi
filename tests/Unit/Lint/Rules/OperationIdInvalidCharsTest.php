<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\OperationIdInvalidChars;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new OperationIdInvalidChars();

    expect($rule->id())->toBe('operation.id-invalid-chars')
        ->and($rule->level())->toBe(1);
});

it('emits a finding for an operationId that violates the charset', function (string $operationId, string $path): void {
    $rule = new OperationIdInvalidChars();
    $operation = OperationNodeFactory::makeOperation(
        pathUri: $path,
        operationId: $operationId,
    );

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.id-invalid-chars')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain($operationId);
})->with([
    'space and exclamation mark' => ['get projects!', '/projects'],
    'starts with a digit'        => ['2fa.enable', '/auth/2fa'],
]);

it('emits no finding for a permitted operationId', function (string $operationId): void {
    $rule = new OperationIdInvalidChars();
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/projects',
        operationId: $operationId,
    );

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    'dot-separated'              => ['projects.list'],
    'hyphens, underscores, digits' => ['projects-list_v2'],
]);

it('emits no finding when operationId is null', function (): void {
    $rule = new OperationIdInvalidChars();
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/projects',
        operationId: null,
    );

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
