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
use Radiergummi\OpenApi\Core\Lint\Rules\SecuritySchemeUndefined;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new SecuritySchemeUndefined();

    expect($rule->id())->toBe('security.scheme-undefined')->and($rule->level())->toBe(0);
});

it('emits no finding when all referenced schemes are declared', function (): void {
    $rule = new SecuritySchemeUndefined();
    $operation = makeSchemeUndefinedOperation(
        security: [['scheme' => 'oauth2', 'scopes' => ['some-scope']]],
    );
    $context = makeSchemeUndefinedContext(declaredSchemes: ['oauth2']);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when a referenced scheme is not declared', function (): void {
    $rule = new SecuritySchemeUndefined();
    $operation = makeSchemeUndefinedOperation(
        security: [['scheme' => 'oauth2', 'scopes' => ['some-scope']]],
    );
    $context = makeSchemeUndefinedContext(declaredSchemes: []);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('security.scheme-undefined')
        ->and($findings[0]->level)
        ->toBe(0)
        ->and($findings[0]->message)
        ->toContain('oauth2');
});

it('emits no finding when operations have no security', function (): void {
    $rule = new SecuritySchemeUndefined();
    $operation = makeSchemeUndefinedOperation(security: []);
    $context = makeSchemeUndefinedContext(declaredSchemes: []);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits findings for multiple undefined schemes on one operation', function (): void {
    $rule = new SecuritySchemeUndefined();
    $operation = makeSchemeUndefinedOperation(
        pathUri: '/multi',
        security: [
            ['scheme' => 'oauth2', 'scopes' => ['read']],
            ['scheme' => 'api_key', 'scopes' => []],
        ],
    );
    $context = makeSchemeUndefinedContext(declaredSchemes: []);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)
        ->toHaveCount(2)
        ->and($findings[0]->message)
        ->toContain('oauth2')
        ->and($findings[1]->message)
        ->toContain('api_key');
});

it('does not emit for declared schemes while emitting for undefined ones', function (): void {
    $rule = new SecuritySchemeUndefined();
    $operation = makeSchemeUndefinedOperation(
        pathUri: '/mixed',
        security: [
            ['scheme' => 'oauth2', 'scopes' => ['read']],
            ['scheme' => 'missing_scheme', 'scopes' => []],
        ],
    );
    $context = makeSchemeUndefinedContext(declaredSchemes: ['oauth2']);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)->and($findings[0]->message)->toContain('missing_scheme');
});

/**
 * @param list<array{scheme: string, scopes: list<string>}> $security
 */
function makeSchemeUndefinedOperation(
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
 * @param list<string> $declaredSchemes
 */
function makeSchemeUndefinedContext(array $declaredSchemes): LintContext
{
    $ctx = new Context();

    $schemes = [];

    foreach ($declaredSchemes as $name) {
        $schemes[] = new OA\SecurityScheme([
            'securityScheme' => $name,
            'type' => 'oauth2',
            '_context' => $ctx,
        ]);
    }

    $components = new OA\Components(['_context' => $ctx]);
    $components->securitySchemes = $schemes;

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
        'components' => $components,
    ]);

    $index = new TreeIndex(
        operationsByOperationId: [],
        operationsByRouteKey: [],
        componentsByName: [],
        referencedComponents: [],
        registeredScopes: [],
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
