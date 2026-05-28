<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\MetaNoSuppressionReason;
use Radiergummi\OpenApi\Lint\SuppressionDirective;
use Radiergummi\OpenApi\Lint\SuppressionScope;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function metaNoSuppressionReasonApi(): ApiNode
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

function metaNoSuppressionReasonDirective(?string $reason): SuppressionDirective
{
    return new SuppressionDirective(
        ruleId: 'response.empty',
        reason: $reason,
        scope: SuppressionScope::ClassScope,
        file: 'F.php',
        line: 5,
        targetClass: 'Acme\\Foo',
    );
}

it('emits for suppressions without a reason', function (): void {
    $api = metaNoSuppressionReasonApi();
    $context = new LintContext(
        api: $api,
        index: TreeIndex::empty(),
        rawSpec: $api->raw,
        actionDescriptors: [],
        suppressions: [metaNoSuppressionReasonDirective(null)],
    );
    $findings = iterator_to_array(new MetaNoSuppressionReason()->checkApi($api, $context));
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('meta.no-suppression-reason');
});

it('does not emit when reason is provided', function (): void {
    $api = metaNoSuppressionReasonApi();
    $context = new LintContext(
        api: $api,
        index: TreeIndex::empty(),
        rawSpec: $api->raw,
        actionDescriptors: [],
        suppressions: [metaNoSuppressionReasonDirective('SSE endpoint')],
    );
    $findings = iterator_to_array(new MetaNoSuppressionReason()->checkApi($api, $context));
    expect($findings)->toBe([]);
});
