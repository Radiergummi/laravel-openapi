<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\SummaryMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new SummaryMissing();

    expect($rule->id())->toBe('summary.missing')
        ->and($rule->level())->toBe(2);
});

it('emits a finding when summary is null', function (): void {
    $rule = new SummaryMissing();
    $operation = makeSummaryMissingNode(summary: null);
    $context = makeSummaryMissingContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('summary.missing')
        ->and($findings[0]->level)->toBe(2)
        ->and($findings[0]->message)->toContain('no summary');
});

it('emits a finding when summary is missing', function (): void {
    $rule = new SummaryMissing();
    $operation = makeSummaryMissingNode(summary: null);
    $context = makeSummaryMissingContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('summary.missing');
});

it('emits a finding when summary is not provided', function (): void {
    $rule = new SummaryMissing();
    $operation = makeSummaryMissingNode(summary: null);
    $context = makeSummaryMissingContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('summary.missing');
});

it('emits no findings when summary is present', function (): void {
    $rule = new SummaryMissing();
    $operation = makeSummaryMissingNode(summary: 'Retrieves a list of resources.');
    $context = makeSummaryMissingContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

/**
 * Build a minimal OperationNode for testing the SummaryMissing rule.
 */
function makeSummaryMissingNode(?string $summary): OperationNode
{
    return new OperationNode(
        pathUri: '/fixture',
        method: 'GET',
        operationId: 'fixture.index',
        summary: $summary,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [
            new ResponseNode(
                statusCode: 200,
                description: 'OK',
                fields: [],
                examples: [],
                schemaRef: null,
                headers: [],
                links: [],
                raw: null,
            ),
        ],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
        webhook: false,
    );
}

function makeSummaryMissingContext(): LintContext
{
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}
