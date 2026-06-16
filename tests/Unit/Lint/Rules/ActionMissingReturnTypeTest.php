<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\ActionMissingReturnType;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ReturnTypeNudgeController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function returnTypeNudgeFindings(string $method): array
{
    $descriptor = ActionDescriptorFactory::forControllerMethod(ReturnTypeNudgeController::class, $method);
    $operation = OperationNodeFactory::forDescriptor($descriptor);

    return iterator_to_array(
        new ActionMissingReturnType()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );
}

it('reports its id and level', function (): void {
    $rule = new ActionMissingReturnType();

    expect($rule->id())
        ->toBe('operation.return-type-missing')
        ->and($rule->severity())->toBe(Severity::Inconsistent);
});

it('emits a finding when the action has no return type and no response attribute', function (): void {
    $findings = returnTypeNudgeFindings('untyped');

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.return-type-missing')
        ->and($findings[0]->severity)->toBe(Severity::Inconsistent)
        ->and($findings[0]->message)->toContain('ReturnTypeNudgeController')
        ->and($findings[0]->message)->toContain('untyped')
        ->and($findings[0]->fixHint)->not->toBeNull();
});

it('emits a finding for a mixed return type', function (): void {
    expect(returnTypeNudgeFindings('mixedReturn'))->toHaveCount(1);
});

it('emits a finding for a void return type', function (): void {
    expect(returnTypeNudgeFindings('voidReturn'))->toHaveCount(1);
});

it('emits no finding', function (string $method): void {
    expect(returnTypeNudgeFindings($method))->toBe([]);
})->with([
    'typed return value' => ['typedArray'],
    'untyped but carries #[Response]' => ['withResponseAttribute'],
    'untyped but carries #[ResponseResource]' => ['withResponseResourceAttribute'],
]);
