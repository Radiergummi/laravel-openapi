<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\ScopeOverlyBroad;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

/**
 * Build an OperationNode with the given security scopes.
 *
 * @param list<array{scheme: string, scopes: list<string>}> $security
 */
function makeScopeOperationNode(array $security): OperationNode
{
    return new OperationNode(
        pathUri: '/foo',
        method: 'GET',
        operationId: null,
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: $security,
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
        webhook: false,
    );
}

/**
 * Build a LintContext with a TreeIndex containing registered scopes.
 *
 * @param list<string> $registeredScopes
 */
function makeContextForScope(array $registeredScopes): LintContext
{
    $index = new TreeIndex(
        operationsByOperationId: [],
        operationsByRouteKey: [],
        componentsByName: [],
        referencedComponents: [],
        registeredScopes: $registeredScopes,
        knownRuleIds: [],
    );

    $spec = new OA\OpenApi(['openapi' => '3.1.0']);

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: $index,
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}

it('reports its id and level', function (): void {
    $rule = new ScopeOverlyBroad(registeredScopes: []);

    expect($rule->id())->toBe('scope.overly-broad')
        ->and($rule->level())->toBe(3);
});

it('emits a finding when the only scope is the wildcard', function (): void {
    $operation = makeScopeOperationNode(
        security: [['scheme' => 'oauth2', 'scopes' => ['*']]],
    );
    $context = makeContextForScope(['read', 'write']);

    $rule = new ScopeOverlyBroad(registeredScopes: ['read', 'write']);
    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('scope.overly-broad')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('wildcard')
        ->and($findings[0]->message)->toContain('GET')
        ->and($findings[0]->message)->toContain('/foo');
});

it('emits no finding when specific scopes are used', function (): void {
    $operation = makeScopeOperationNode(
        security: [['scheme' => 'oauth2', 'scopes' => ['read', 'write']]],
    );
    $context = makeContextForScope(['read', 'write']);

    $rule = new ScopeOverlyBroad(registeredScopes: ['read', 'write']);
    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no finding when wildcard is mixed with specific scopes', function (): void {
    $operation = makeScopeOperationNode(
        security: [['scheme' => 'oauth2', 'scopes' => ['*', 'read']]],
    );
    $context = makeContextForScope(['read', 'write']);

    $rule = new ScopeOverlyBroad(registeredScopes: ['read', 'write']);
    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no finding when operation has no security', function (): void {
    $operation = makeScopeOperationNode(security: []);
    $context = makeContextForScope(['read']);

    $rule = new ScopeOverlyBroad(registeredScopes: ['read']);
    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no finding when no specific scopes are registered', function (): void {
    $operation = makeScopeOperationNode(
        security: [['scheme' => 'oauth2', 'scopes' => ['*']]],
    );
    $context = makeContextForScope([]);

    $rule = new ScopeOverlyBroad(registeredScopes: []);
    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no finding when the only registered scope is wildcard', function (): void {
    $operation = makeScopeOperationNode(
        security: [['scheme' => 'oauth2', 'scopes' => ['*']]],
    );
    $context = makeContextForScope(['*']);

    $rule = new ScopeOverlyBroad(registeredScopes: ['*']);
    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});
