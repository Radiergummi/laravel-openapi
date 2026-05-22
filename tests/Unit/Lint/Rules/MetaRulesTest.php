<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\MetaNoSuppressionReason;
use Radiergummi\OpenApi\Core\Lint\Rules\MetaUnknownRule;
use Radiergummi\OpenApi\Core\Lint\SuppressionDirective;
use Radiergummi\OpenApi\Core\Lint\SuppressionScope;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeMetaRulesApiNode(): ApiNode
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

function metaRulesDirective(
    string $ruleId,
    ?string $reason = null,
): SuppressionDirective {
    return new SuppressionDirective(
        ruleId: $ruleId,
        reason: $reason,
        scope: SuppressionScope::ClassScope,
        file: 'F.php',
        line: 5,
        targetClass: 'Acme\\Foo',
    );
}

// MetaNoSuppressionReason
it('emits for suppressions without a reason', function (): void {
    $api = makeMetaRulesApiNode();
    $ctx = new LintContext(
        api: $api,
        index: TreeIndex::empty(),
        rawSpec: $api->raw,
        actionDescriptors: [],
        suppressions: [metaRulesDirective('response.empty')],
    );
    $findings = iterator_to_array(new MetaNoSuppressionReason()->checkApi($api, $ctx));
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('meta.no-suppression-reason');
});

it('does not emit when reason is provided', function (): void {
    $api = makeMetaRulesApiNode();
    $ctx = new LintContext(
        api: $api,
        index: TreeIndex::empty(),
        rawSpec: $api->raw,
        actionDescriptors: [],
        suppressions: [metaRulesDirective('response.empty', 'SSE endpoint')],
    );
    $findings = iterator_to_array(new MetaNoSuppressionReason()->checkApi($api, $ctx));
    expect($findings)->toBe([]);
});

// MetaUnknownRule
it('emits for unknown rule ids in suppressions', function (): void {
    $api = makeMetaRulesApiNode();
    $index = new TreeIndex(
        operationsByOperationId: [],
        operationsByRouteKey: [],
        componentsByName: [],
        referencedComponents: [],
        registeredScopes: [],
        knownRuleIds: ['response.empty', 'throws.unmapped'],
    );
    $ctx = new LintContext(
        api: $api,
        index: $index,
        rawSpec: $api->raw,
        actionDescriptors: [],
        suppressions: [metaRulesDirective('nonexistent.rule', 'oops')],
    );
    $rule = new MetaUnknownRule();
    $findings = iterator_to_array($rule->checkApi($api, $ctx));
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('meta.unknown-rule')
        ->and($findings[0]->context['unknown_id'])->toBe('nonexistent.rule');
});

it('does not emit for known rule ids', function (): void {
    $api = makeMetaRulesApiNode();
    $index = new TreeIndex(
        operationsByOperationId: [],
        operationsByRouteKey: [],
        componentsByName: [],
        referencedComponents: [],
        registeredScopes: [],
        knownRuleIds: ['response.empty'],
    );
    $ctx = new LintContext(
        api: $api,
        index: $index,
        rawSpec: $api->raw,
        actionDescriptors: [],
        suppressions: [metaRulesDirective('response.empty', 'legacy')],
    );
    $rule = new MetaUnknownRule();
    $findings = iterator_to_array($rule->checkApi($api, $ctx));
    expect($findings)->toBe([]);
});
