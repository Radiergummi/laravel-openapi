<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\MetaUnknownRule;
use Radiergummi\OpenApi\Lint\SuppressionDirective;
use Radiergummi\OpenApi\Lint\SuppressionScope;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function metaUnknownRuleApi(): ApiNode
{
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);

    return new ApiNode(
        operations: [],
        components: [],
        webhooks: [],
        declaredTags: [],
        tagDescriptions: [],
        raw: $spec,
    );
}

function metaUnknownRuleDirective(string $ruleId): SuppressionDirective
{
    return new SuppressionDirective(
        ruleId: $ruleId,
        reason: 'oops',
        scope: SuppressionScope::ClassScope,
        file: 'F.php',
        line: 5,
        targetClass: 'Acme\\Foo',
    );
}

it('emits for unknown rule ids in suppressions', function (): void {
    $api = metaUnknownRuleApi();
    $index = new TreeIndex(
        operationsByOperationId: [],
        operationsByRouteKey: [],
        componentsByName: [],
        referencedComponents: [],
        registeredScopes: [],
        knownRuleIds: ['response.empty', 'throws.unmapped'],
    );
    $context = new LintContext(
        api: $api,
        index: $index,
        rawSpec: $api->raw,
        actionDescriptors: [],
        suppressions: [metaUnknownRuleDirective('nonexistent.rule')],
    );
    $findings = iterator_to_array(new MetaUnknownRule()->checkApi($api, $context));
    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('meta.unknown-rule')
        ->and($findings[0]->context['unknown_id'])->toBe('nonexistent.rule');
});

it('does not emit for known rule ids', function (): void {
    $api = metaUnknownRuleApi();
    $index = new TreeIndex(
        operationsByOperationId: [],
        operationsByRouteKey: [],
        componentsByName: [],
        referencedComponents: [],
        registeredScopes: [],
        knownRuleIds: ['response.empty'],
    );
    $context = new LintContext(
        api: $api,
        index: $index,
        rawSpec: $api->raw,
        actionDescriptors: [],
        suppressions: [metaUnknownRuleDirective('response.empty')],
    );
    $findings = iterator_to_array(new MetaUnknownRule()->checkApi($api, $context));
    expect($findings)->toBe([]);
});
