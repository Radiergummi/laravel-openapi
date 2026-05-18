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
use Radiergummi\OpenApi\Core\Lint\Rules\OperationDescriptionMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new OperationDescriptionMissing();

    expect($rule->id())->toBe('operation.description-missing')
        ->and($rule->level())->toBe(2);
});

it('emits a finding when operation has summary but no description', function (): void {
    $rule = new OperationDescriptionMissing();
    $operation = makeOperationDescriptionMissingNode(
        summary: 'List all users',
        description: null,
    );
    $context = makeOperationDescriptionMissingContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.description-missing')
        ->and($findings[0]->level)->toBe(2)
        ->and($findings[0]->message)->toContain('GET')
        ->and($findings[0]->message)->toContain('/test');
});

it('emits a finding when operation has no description (null)', function (): void {
    $rule = new OperationDescriptionMissing();
    $operation = makeOperationDescriptionMissingNode(
        summary: 'List all users',
        description: null,
    );
    $context = makeOperationDescriptionMissingContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1);
});

it('emits a finding when operation has null description regardless of summary', function (): void {
    $rule = new OperationDescriptionMissing();
    $operation = makeOperationDescriptionMissingNode(
        summary: 'List all users',
        description: null,
    );
    $context = makeOperationDescriptionMissingContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1);
});

it('emits no findings when operation has both summary and description', function (): void {
    $rule = new OperationDescriptionMissing();
    $operation = makeOperationDescriptionMissingNode(
        summary: 'List all users',
        description: 'Returns a paginated list of all users in the system.',
    );
    $context = makeOperationDescriptionMissingContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when operation has no summary and no description', function (): void {
    // Operations missing both are covered by the summary.missing rule instead.
    $rule = new OperationDescriptionMissing();
    $operation = makeOperationDescriptionMissingNode(
        summary: null,
        description: null,
    );
    $context = makeOperationDescriptionMissingContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when operation has description but no summary', function (): void {
    $rule = new OperationDescriptionMissing();
    $operation = makeOperationDescriptionMissingNode(
        summary: null,
        description: 'A detailed description.',
    );
    $context = makeOperationDescriptionMissingContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

/**
 * Build a minimal OperationNode for testing the OperationDescriptionMissing rule.
 */
function makeOperationDescriptionMissingNode(
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

function makeOperationDescriptionMissingContext(): LintContext
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
