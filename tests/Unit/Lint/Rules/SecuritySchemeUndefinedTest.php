<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\SecuritySchemeUndefined;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\TreeIndex;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

/**
 * `SecuritySchemeUndefined` walks `rawSpec->components->securitySchemes`, which
 * `OperationNodeFactory::emptyContext()` deliberately ships empty. Keep the spec-builder local
 * rather than bloating the factory with rawSpec knobs.
 *
 * @param list<string> $declaredSchemes
 */
function makeSchemeUndefinedContext(array $declaredSchemes): LintContext
{
    $context = new Context();
    $schemes = [];

    foreach ($declaredSchemes as $name) {
        $schemes[] = new OA\SecurityScheme([
            'securityScheme' => $name,
            'type' => 'oauth2',
            '_context' => $context,
        ]);
    }

    $components = new OA\Components(['_context' => $context]);
    $components->securitySchemes = $schemes;

    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $context]),
        'components' => $components,
    ]);

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}

it('reports its id and level', function (): void {
    $rule = new SecuritySchemeUndefined();

    expect($rule->id)->toBe('security.scheme-undefined')->and($rule->severity)->toBe(Severity::Broken);
});

it('emits no finding when all referenced schemes are declared', function (): void {
    $rule = new SecuritySchemeUndefined();
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/foo',
        operationId: 'test.operation',
        responses: [],
        security: [['scheme' => 'oauth2', 'scopes' => ['some-scope']]],
    );
    $context = makeSchemeUndefinedContext(declaredSchemes: ['oauth2']);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits a finding when a referenced scheme is not declared', function (): void {
    $rule = new SecuritySchemeUndefined();
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/foo',
        operationId: 'test.operation',
        responses: [],
        security: [['scheme' => 'oauth2', 'scopes' => ['some-scope']]],
    );
    $context = makeSchemeUndefinedContext(declaredSchemes: []);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('security.scheme-undefined')
        ->and($findings[0]->severity)
        ->toBe(Severity::Broken)
        ->and($findings[0]->message)
        ->toContain('oauth2');
});

it('emits no finding when operations have no security', function (): void {
    $rule = new SecuritySchemeUndefined();
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/foo',
        operationId: 'test.operation',
        responses: [],
        security: [],
    );
    $context = makeSchemeUndefinedContext(declaredSchemes: []);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits findings for multiple undefined schemes on one operation', function (): void {
    $rule = new SecuritySchemeUndefined();
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/multi',
        operationId: 'test.operation',
        responses: [],
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
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/mixed',
        operationId: 'test.operation',
        responses: [],
        security: [
            ['scheme' => 'oauth2', 'scopes' => ['read']],
            ['scheme' => 'missing_scheme', 'scopes' => []],
        ],
    );
    $context = makeSchemeUndefinedContext(declaredSchemes: ['oauth2']);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)->and($findings[0]->message)->toContain('missing_scheme');
});
