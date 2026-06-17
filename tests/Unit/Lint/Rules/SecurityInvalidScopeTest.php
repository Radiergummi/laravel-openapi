<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\SecurityInvalidScope;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\TreeIndex;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * A `LintContext` whose `rawSpec` declares security schemes with explicit types, so scope rules can
 * resolve a requirement's scheme `type`. `OperationNodeFactory::emptyContext()` ships no
 * securitySchemes; keep the spec-builder local rather than bloating the factory with rawSpec knobs.
 *
 * @param array<string, string> $schemeTypes      scheme name → OAS type (oauth2, http, apiKey, …)
 * @param list<string>          $registeredScopes prepopulates `TreeIndex->registeredScopes`
 */
function makeScopeSchemeContext(array $schemeTypes, array $registeredScopes): LintContext
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
        api: new ApiNode(
            operations: [],
            components: [],
            webhooks: [],
            declaredTags: [],
            tagDescriptions: [],
            raw: $spec,
        ),
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
    $rule = new SecurityInvalidScope(registeredScopes: []);

    expect($rule->id())->toBe('security.invalid-scope')->and($rule->severity())->toBe(Severity::Degraded);
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
        ->and($findings[0]->severity)
        ->toBe(Severity::Degraded)
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

it('does not flag Sanctum abilities on a non-oauth2 (http/bearer) scheme', function (): void {
    // A hybrid Passport + Sanctum app: Passport scopes are registered, but the route authenticates
    // via Sanctum, whose `abilities:` surface as scopes on the `http`/bearer `sanctum` scheme.
    $rule = new SecurityInvalidScope();
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/posts',
        operationId: 'posts.store',
        responses: [],
        security: [['scheme' => 'sanctum', 'scopes' => ['read', 'write']]],
    );
    $context = makeScopeSchemeContext(
        schemeTypes: ['sanctum' => 'http', 'passport' => 'oauth2'],
        registeredScopes: ['posts:read', 'posts:write'],
    );

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('still flags an undefined scope on an oauth2 scheme', function (): void {
    $rule = new SecurityInvalidScope();
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/posts',
        operationId: 'posts.store',
        responses: [],
        security: [['scheme' => 'passport', 'scopes' => ['unknown-scope']]],
    );
    $context = makeScopeSchemeContext(
        schemeTypes: ['passport' => 'oauth2'],
        registeredScopes: ['known-scope'],
    );

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)->and($findings[0]->message)->toContain('unknown-scope');
});
