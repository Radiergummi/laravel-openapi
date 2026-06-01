<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\ScopeOverlyBroad;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new ScopeOverlyBroad(registeredScopes: []);

    expect($rule->id())->toBe('scope.overly-broad')
        ->and($rule->level())->toBe(3);
});

it('emits a finding when the only scope is the wildcard', function (): void {
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/foo',
        operationId: null,
        responses: [],
        security: [['scheme' => 'oauth2', 'scopes' => ['*']]],
    );
    $context = OperationNodeFactory::emptyContext(registeredScopes: ['read', 'write']);

    $rule = new ScopeOverlyBroad(registeredScopes: ['read', 'write']);
    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('scope.overly-broad')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('wildcard')
        ->and($findings[0]->message)->toContain('GET')
        ->and($findings[0]->message)->toContain('/foo');
});

it('emits no finding', function (array $scopes, array $registeredScopes): void {
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/foo',
        operationId: null,
        responses: [],
        security: $scopes === [] ? [] : [['scheme' => 'oauth2', 'scopes' => $scopes]],
    );
    $context = OperationNodeFactory::emptyContext(registeredScopes: $registeredScopes);

    $rule = new ScopeOverlyBroad(registeredScopes: $registeredScopes);
    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
})->with([
    'specific scopes used' => [['read', 'write'], ['read', 'write']],
    'wildcard mixed with specific scopes' => [['*', 'read'], ['read', 'write']],
    'no security on operation' => [[], ['read']],
    'no specific scopes registered' => [['*'], []],
    'only registered scope is wildcard' => [['*'], ['*']],
]);
