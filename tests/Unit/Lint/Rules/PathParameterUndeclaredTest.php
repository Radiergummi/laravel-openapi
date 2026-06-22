<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\PathParameterUndeclared;
use Radiergummi\OpenApi\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new PathParameterUndeclared();

    expect($rule->id)->toBe('path.parameter-undeclared')->and($rule->severity)->toBe(Severity::Broken);
});

it('emits no finding when all placeholders are declared as path parameters', function (string $pathUri, array $names): void {
    $operation = OperationNodeFactory::makeOperation(
        pathUri: $pathUri,
        parameters: array_map(static fn(string $n): ParameterNode => OperationNodeFactory::makeParameter(name: $n), $names),
        responses: [],
    );

    $findings = iterator_to_array(
        new PathParameterUndeclared()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    'single placeholder declared'      => ['/users/{userId}', ['userId']],
    'RFC 6570 operator-prefixed name'  => ['/files/{+path}', ['path']],
    'no placeholders at all'           => ['/users', []],
    'custom-key binding {param:field}' => ['/members/{member:slug}', ['member']],
    'custom-key + optional'            => ['/members/{member:slug?}', ['member']],
    'operator prefix + custom key'     => ['/files/{+path:disk}', ['path']],
]);

it('reports an undeclared custom-key placeholder under its bare name', function (): void {
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/members/{member:slug}',
        parameters: [],
        responses: [],
    );

    $findings = iterator_to_array(
        new PathParameterUndeclared()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->message)->toContain('"{member}"')
        ->and($findings[0]->fixHint)->toContain('name="member"');
});

it('emits a finding for an undeclared path placeholder', function (): void {
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/users/{userId}/posts/{postId}',
        parameters: [OperationNodeFactory::makeParameter(name: 'userId')],
        responses: [],
    );

    $findings = iterator_to_array(
        new PathParameterUndeclared()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('path.parameter-undeclared')
        ->and($findings[0]->severity)->toBe(Severity::Broken)
        ->and($findings[0]->message)->toContain('postId');
});

it('emits a finding for each undeclared placeholder', function (): void {
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/users/{userId}/posts/{postId}',
        parameters: [],
        responses: [],
    );

    $findings = iterator_to_array(
        new PathParameterUndeclared()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->message)->toContain('userId')
        ->and($findings[1]->message)->toContain('postId');
});
