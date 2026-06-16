<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Rules\MetaSuppressionStale;
use Radiergummi\OpenApi\Lint\SuppressionDirective;
use Radiergummi\OpenApi\Lint\SuppressionScope;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function staleContext(SuppressionDirective $directive): LintContext
{
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);

    return new LintContext(
        api: new ApiNode(
            operations: [],
            components: [],
            webhooks: [],
            declaredTags: [],
            tagDescriptions: [],
            raw: $spec,
        ),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [$directive],
    );
}

function staleDirective(
    string $ruleId,
    string $file = 'Controller.php',
): SuppressionDirective {
    return new SuppressionDirective(
        ruleId: $ruleId,
        reason: 'old issue',
        scope: SuppressionScope::ClassScope,
        file: $file,
        line: 10,
        targetClass: 'Acme\\Http\\Controllers\\Controller',
    );
}

it('reports its id and level', function (): void {
    $rule = new MetaSuppressionStale();

    expect($rule->id())
        ->toBe('meta.suppression-stale')
        ->and($rule->level())->toBe(3);
});

it('emits a finding when a suppression did not match any finding', function (): void {
    $context = staleContext(staleDirective('response.empty'));

    $rule = new MetaSuppressionStale();
    $findings = iterator_to_array($rule->check($context, []));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('meta.suppression-stale')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('response.empty')
        ->and($findings[0]->message)->toContain('stale')
        ->and($findings[0]->location->file)->toBe('Controller.php')
        ->and($findings[0]->location->line)->toBe(10);
});

it('emits no finding when a suppression matched a finding via source-class context', function (): void {
    $matchingFinding = new Finding(
        ruleId: 'response.empty',
        level: 0,
        message: 'No responses',
        context: [
            Finding::CONTEXT_SOURCE_CLASS => 'Acme\\Http\\Controllers\\Controller',
        ],
    );

    $context = staleContext(staleDirective('response.empty'));

    $rule = new MetaSuppressionStale();
    $findings = iterator_to_array($rule->check($context, [$matchingFinding]));

    expect($findings)->toBe([]);
});

it('emits when finding ruleId does not match the directive', function (): void {
    $unrelatedFinding = new Finding(
        ruleId: 'summary.missing',
        level: 0,
        message: 'Missing summary',
        context: [
            Finding::CONTEXT_SOURCE_CLASS => 'Acme\\Http\\Controllers\\Controller',
        ],
    );

    $context = staleContext(staleDirective('response.empty'));

    $rule = new MetaSuppressionStale();
    $findings = iterator_to_array($rule->check($context, [$unrelatedFinding]));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->message)->toContain('response.empty');
});

it('does not match a finding from a different class', function (): void {
    $findingFromOtherClass = new Finding(
        ruleId: 'response.empty',
        level: 0,
        message: 'No responses',
        context: [
            Finding::CONTEXT_SOURCE_CLASS => 'Acme\\Http\\Controllers\\OtherController',
        ],
    );

    $context = staleContext(staleDirective('response.empty'));

    $rule = new MetaSuppressionStale();
    $findings = iterator_to_array($rule->check($context, [$findingFromOtherClass]));

    expect($findings)->toHaveCount(1);
});
