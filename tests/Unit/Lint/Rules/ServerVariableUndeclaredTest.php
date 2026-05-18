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
use Radiergummi\OpenApi\Core\Lint\Rules\ServerVariableUndeclared;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeServerVariableUndeclaredContext(OA\OpenApi $spec): LintContext
{
    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}

function makeSpecWithTemplateServer(string $url, array $variables = []): OA\OpenApi
{
    $ctx = new Context();
    $spec = new OA\OpenApi(['openapi' => '3.1.0', '_context' => $ctx]);
    $serverData = ['url' => $url, '_context' => $ctx];

    if ($variables !== []) {
        $serverVariables = [];

        foreach ($variables as $varName => $default) {
            $sv = new OA\ServerVariable(['serverVariable' => $varName, 'default' => $default, '_context' => $ctx]);
            $serverVariables[$varName] = $sv;
        }

        $serverData['variables'] = $serverVariables;
    }

    $server = new OA\Server($serverData);
    $spec->servers = [$server];

    return $spec;
}

it('has the correct rule id and level', function (): void {
    $rule = new ServerVariableUndeclared();

    expect($rule->id())->toBe('server.variable-undeclared')
        ->and($rule->level())->toBe(0);
});

it('emits a finding when a template variable has no matching variables entry', function (): void {
    $rule = new ServerVariableUndeclared();
    $spec = makeSpecWithTemplateServer('https://{region}.example.com');
    $context = makeServerVariableUndeclaredContext($spec);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('server.variable-undeclared')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('region');
});

it('emits no findings when all template variables are declared', function (): void {
    $rule = new ServerVariableUndeclared();
    $spec = makeSpecWithTemplateServer('https://{region}.example.com', ['region' => 'eu']);
    $context = makeServerVariableUndeclaredContext($spec);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toBe([]);
});

it('emits no findings for a URL with no template variables', function (): void {
    $rule = new ServerVariableUndeclared();
    $spec = makeSpecWithTemplateServer('https://api.example.com');
    $context = makeServerVariableUndeclaredContext($spec);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toBe([]);
});

it('emits no findings when servers is UNDEFINED', function (): void {
    $rule = new ServerVariableUndeclared();
    $ctx = new Context();
    $spec = new OA\OpenApi(['openapi' => '3.1.0', '_context' => $ctx]);
    $context = makeServerVariableUndeclaredContext($spec);

    $findings = iterator_to_array($rule->checkApi($context->api, $context));

    expect($findings)->toBe([]);
});
