<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\ScopeOverlyBroad;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\TreeIndex;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * A `LintContext` whose `rawSpec` declares security schemes with explicit types, so scope rules can
 * resolve a requirement's scheme `type`.
 *
 * @param array<string, string> $schemeTypes      scheme name → OAS type (oauth2, http, apiKey, …)
 * @param list<string>          $registeredScopes prepopulates `TreeIndex->registeredScopes`
 */
function makeOverlyBroadSchemeContext(array $schemeTypes, array $registeredScopes): LintContext
{
    $oaContext = new Context();
    $schemes = [];

    foreach ($schemeTypes as $name => $type) {
        $schemes[] = new OA\SecurityScheme([
            'securityScheme' => $name,
            'type' => $type,
            '_context' => $oaContext,
        ]);
    }

    $components = new OA\Components(['_context' => $oaContext]);
    $components->securitySchemes = $schemes;

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $oaContext]),
        'components' => $components,
    ]);

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: new TreeIndex(
            operationsByOperationId: [],
            operationsByRouteKey: [],
            componentsByName: [],
            referencedComponents: [],
            registeredScopes: $registeredScopes,
            knownRuleIds: [],
        ),
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

it('does not flag a wildcard-only scope on a non-oauth2 (http/bearer) scheme', function (): void {
    $rule = new ScopeOverlyBroad(registeredScopes: ['posts:read', 'posts:write']);
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/posts',
        operationId: null,
        responses: [],
        security: [['scheme' => 'sanctum', 'scopes' => ['*']]],
    );
    $context = makeOverlyBroadSchemeContext(
        schemeTypes: ['sanctum' => 'http'],
        registeredScopes: ['posts:read', 'posts:write'],
    );

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('still flags a wildcard-only scope on an oauth2 scheme', function (): void {
    $rule = new ScopeOverlyBroad(registeredScopes: ['posts:read', 'posts:write']);
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/posts',
        operationId: null,
        responses: [],
        security: [['scheme' => 'passport', 'scopes' => ['*']]],
    );
    $context = makeOverlyBroadSchemeContext(
        schemeTypes: ['passport' => 'oauth2'],
        registeredScopes: ['posts:read', 'posts:write'],
    );

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)->and($findings[0]->message)->toContain('wildcard');
});
