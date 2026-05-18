<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\SecurityInvalidScope;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new SecurityInvalidScope(registeredScopes: []);

    expect($rule->id())->toBe('security.invalid-scope')->and($rule->level())->toBe(1);
});

it('emits a finding when an operation references an undefined scope', function (): void {
    $rule = new SecurityInvalidScope(registeredScopes: ['known-scope']);
    $operation = makeSecurityInvalidScopeOperation(
        security: [['scheme' => 'oauth2', 'scopes' => ['unknown-scope']]],
    );
    $context = makeSecurityInvalidScopeContext(registeredScopes: ['known-scope']);

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

it('emits no finding when all scopes are registered', function (): void {
    $rule = new SecurityInvalidScope(registeredScopes: ['known-scope']);
    $operation = makeSecurityInvalidScopeOperation(
        security: [['scheme' => 'oauth2', 'scopes' => ['known-scope']]],
    );
    $context = makeSecurityInvalidScopeContext(registeredScopes: ['known-scope']);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no finding when operations have no security', function (): void {
    $rule = new SecurityInvalidScope(registeredScopes: ['some-scope']);
    $operation = makeSecurityInvalidScopeOperation(security: []);
    $context = makeSecurityInvalidScopeContext(registeredScopes: ['some-scope']);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits a finding per invalid scope', function (): void {
    $rule = new SecurityInvalidScope(registeredScopes: ['good-one']);
    $operation = makeSecurityInvalidScopeOperation(
        security: [['scheme' => 'oauth2', 'scopes' => ['bad-one', 'good-one', 'bad-two']]],
    );
    $context = makeSecurityInvalidScopeContext(registeredScopes: ['good-one']);

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
    $operation = makeSecurityInvalidScopeOperation(
        pathUri: '/multi',
        security: [
            ['scheme' => 'oauth2', 'scopes' => ['valid-scope']],
            ['scheme' => 'api_key', 'scopes' => ['invalid-scope']],
        ],
    );
    $context = makeSecurityInvalidScopeContext(registeredScopes: ['valid-scope']);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)->and($findings[0]->message)->toContain('invalid-scope');
});

it(
    'falls back to context index when no registered scopes passed to constructor',
    function (): void {
        $rule = new SecurityInvalidScope();
        $operation = makeSecurityInvalidScopeOperation(
            security: [['scheme' => 'oauth2', 'scopes' => ['unknown-scope']]],
        );
        $context = makeSecurityInvalidScopeContext(registeredScopes: ['known-scope']);

        $findings = iterator_to_array($rule->checkOperation($operation, $context));

        expect($findings)->toHaveCount(1)->and($findings[0]->message)->toContain('unknown-scope');
    },
);

/**
 * @param list<array{scheme: string, scopes: list<string>}> $security
 */
function makeSecurityInvalidScopeOperation(
    array $security,
    string $pathUri = '/foo',
    string $method = 'GET',
): OperationNode {
    return new OperationNode(
        pathUri: $pathUri,
        method: $method,
        operationId: 'test.operation',
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
    );
}

/**
 * @param list<string> $registeredScopes
 */
function makeSecurityInvalidScopeContext(array $registeredScopes): LintContext
{
    $ctx = new Context();
    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
    ]);

    $index = new TreeIndex(
        operationsByOperationId: [],
        operationsByRouteKey: [],
        componentsByName: [],
        referencedComponents: [],
        registeredScopes: $registeredScopes,
        knownRuleIds: [],
    );

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: $index,
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}
