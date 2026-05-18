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
use Radiergummi\OpenApi\Core\Lint\Rules\OperationSummaryEqualsDescription;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new OperationSummaryEqualsDescription();

    expect($rule->id())->toBe('operation.summary-equals-description')
        ->and($rule->level())->toBe(3);
});

it('emits a finding when summary and description are identical', function (): void {
    $rule = new OperationSummaryEqualsDescription();
    $operation = makeOperationSummaryEqualsDescriptionNode(
        summary: 'List projects',
        description: 'List projects',
    );
    $context = makeOperationSummaryEqualsDescriptionContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.summary-equals-description')
        ->and($findings[0]->level)->toBe(3);
});

it('emits a finding when summary and description match after trim and case-insensitive compare', function (): void {
    $rule = new OperationSummaryEqualsDescription();
    $operation = makeOperationSummaryEqualsDescriptionNode(
        summary: 'List projects',
        description: '  list projects  ',
    );
    $context = makeOperationSummaryEqualsDescriptionContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.summary-equals-description');
});

it('emits no findings when description adds more detail than the summary', function (): void {
    $rule = new OperationSummaryEqualsDescription();
    $operation = makeOperationSummaryEqualsDescriptionNode(
        summary: 'List projects',
        description: 'Returns a paginated list of all projects.',
    );
    $context = makeOperationSummaryEqualsDescriptionContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when summary is set but description is null', function (): void {
    $rule = new OperationSummaryEqualsDescription();
    $operation = makeOperationSummaryEqualsDescriptionNode(
        summary: 'List projects',
        description: null,
    );
    $context = makeOperationSummaryEqualsDescriptionContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when summary is null and description is set', function (): void {
    $rule = new OperationSummaryEqualsDescription();
    $operation = makeOperationSummaryEqualsDescriptionNode(
        summary: null,
        description: 'Returns a paginated list of all projects.',
    );
    $context = makeOperationSummaryEqualsDescriptionContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

/**
 * Build a minimal OperationNode for testing the OperationSummaryEqualsDescription rule.
 */
function makeOperationSummaryEqualsDescriptionNode(
    ?string $summary = null,
    ?string $description = null,
): OperationNode {
    return new OperationNode(
        pathUri: '/test',
        method: 'GET',
        operationId: 'test.index',
        summary: $summary,
        description: $description,
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

function makeOperationSummaryEqualsDescriptionContext(): LintContext
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
        suppressions: [],
    );
}
