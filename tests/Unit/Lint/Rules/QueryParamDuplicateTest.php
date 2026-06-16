<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\QueryParamDuplicate;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\DuplicateQueryParamController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function makeQueryParamDuplicateOperation(string $method): OperationNode
{
    $descriptor = ActionDescriptorFactory::forControllerMethod(DuplicateQueryParamController::class, $method, '/fixture');

    return OperationNodeFactory::forDescriptor(
        $descriptor,
        pathUri: '/fixture',
        operationId: 'fixture.' . $method,
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new QueryParamDuplicate();

    expect($rule->id())->toBe('queryparam.duplicate')
        ->and($rule->severity())->toBe(Severity::Degraded);
});

it('emits a finding when a method has duplicate query param names', function (): void {
    $rule = new QueryParamDuplicate();
    $operation = makeQueryParamDuplicateOperation('withDuplicates');

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('queryparam.duplicate')
        ->and($findings[0]->severity)->toBe(Severity::Degraded)
        ->and($findings[0]->message)->toContain('"q"')
        ->and($findings[0]->message)->toContain('2 times');
});

it('emits no findings when query param names are unique', function (): void {
    $rule = new QueryParamDuplicate();
    $operation = makeQueryParamDuplicateOperation('withoutDuplicates');

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});

it('emits no findings when a method has no query parameters', function (): void {
    $rule = new QueryParamDuplicate();
    $operation = makeQueryParamDuplicateOperation('withoutQueryParams');

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});

it('emits no findings when the operation has no descriptor', function (): void {
    $rule = new QueryParamDuplicate();
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/no-descriptor',
        operationId: 'no.descriptor',
        responses: [],
    );

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});
