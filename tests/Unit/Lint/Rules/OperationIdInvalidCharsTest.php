<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\SanitizeOperationIdFixer;
use Radiergummi\OpenApi\Lint\Rules\OperationIdInvalidChars;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix\InvalidOperationIdFixtureController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new OperationIdInvalidChars();

    expect($rule->id())->toBe('operation.id-invalid-chars')
        ->and($rule->severity())->toBe(Severity::Degraded);
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
        ->and($findings[0]->severity)->toBe(Severity::Degraded)
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

it('stamps the sanitised operationId and source member onto the finding when a descriptor is present', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(
        InvalidOperationIdFixtureController::class,
        'withSpaces',
    );
    $operation = OperationNodeFactory::forDescriptor($descriptor, operationId: 'list users!');

    $findings = iterator_to_array(
        (new OperationIdInvalidChars())->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->context[Finding::CONTEXT_SOURCE_CLASS])->toBe(InvalidOperationIdFixtureController::class)
        ->and($findings[0]->context[Finding::CONTEXT_SOURCE_MEMBER])->toBe('withSpaces')
        ->and($findings[0]->context[SanitizeOperationIdFixer::CONTEXT_OPERATION_ID])->toBe('list_users_');
});

it('stamps no fix context when the operation has no descriptor', function (): void {
    $operation = OperationNodeFactory::makeOperation(pathUri: '/projects', operationId: 'list users!');

    $findings = iterator_to_array(
        (new OperationIdInvalidChars())->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->context)->toBe([]);
});

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
