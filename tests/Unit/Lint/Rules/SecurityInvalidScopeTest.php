<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\Rules\SecurityInvalidScope;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new SecurityInvalidScope(registeredScopes: []);

    expect($rule->id())->toBe('security.invalid-scope')->and($rule->level())->toBe(1);
});

it('emits a finding when an operation references an undefined scope', function (): void {
    $rule = new SecurityInvalidScope(registeredScopes: ['known-scope']);
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/foo',
        operationId: 'test.operation',
        responses: [],
        security: [['scheme' => 'oauth2', 'scopes' => ['unknown-scope']]],
    );
    $context = OperationNodeFactory::emptyContext(registeredScopes: ['known-scope']);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('security.invalid-scope')
        ->and($findings[0]->level)
        ->toBe(1)
        ->and($findings[0]->message)
        ->toContain('unknown-scope');
});

it('emits no finding', function (array $security, array $registeredScopes): void {
    $rule = new SecurityInvalidScope(registeredScopes: $registeredScopes);
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/foo',
        operationId: 'test.operation',
        responses: [],
        security: $security,
    );
    $context = OperationNodeFactory::emptyContext(registeredScopes: $registeredScopes);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
})->with([
    'all scopes registered' => [
        [['scheme' => 'oauth2', 'scopes' => ['known-scope']]],
        ['known-scope'],
    ],
    'operation has no security' => [
        [],
        ['some-scope'],
    ],
]);

it('emits a finding per invalid scope', function (): void {
    $rule = new SecurityInvalidScope(registeredScopes: ['good-one']);
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/foo',
        operationId: 'test.operation',
        responses: [],
        security: [['scheme' => 'oauth2', 'scopes' => ['bad-one', 'good-one', 'bad-two']]],
    );
    $context = OperationNodeFactory::emptyContext(registeredScopes: ['good-one']);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->message)
        ->toContain('bad-one')
        ->and($findings[1]->message)
        ->toContain('bad-two');
});

it('handles multiple security schemes on one operation', function (): void {
    $rule = new SecurityInvalidScope(registeredScopes: ['valid-scope']);
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/multi',
        operationId: 'test.operation',
        responses: [],
        security: [
            ['scheme' => 'oauth2', 'scopes' => ['valid-scope']],
            ['scheme' => 'api_key', 'scopes' => ['invalid-scope']],
        ],
    );
    $context = OperationNodeFactory::emptyContext(registeredScopes: ['valid-scope']);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)->and($findings[0]->message)->toContain('invalid-scope');
});

it('falls back to context index when no registered scopes passed to constructor', function (): void {
    $rule = new SecurityInvalidScope();
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/foo',
        operationId: 'test.operation',
        responses: [],
        security: [['scheme' => 'oauth2', 'scopes' => ['unknown-scope']]],
    );
    $context = OperationNodeFactory::emptyContext(registeredScopes: ['known-scope']);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)->and($findings[0]->message)->toContain('unknown-scope');
});
