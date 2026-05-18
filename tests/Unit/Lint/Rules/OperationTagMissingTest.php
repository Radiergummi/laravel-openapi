<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\OperationTagMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new OperationTagMissing();

    expect($rule->id())->toBe('operation.tag-missing')
        ->and($rule->level())->toBe(1);
});

it('emits a finding when an operation has no tags', function (): void {
    $rule = new OperationTagMissing();
    $operation = makeOperationTagMissingNode('/users', 'GET', tags: []);
    $context = makeOperationTagMissingContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.tag-missing')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('/users');
});

it('emits a finding when tags is an empty array', function (): void {
    $rule = new OperationTagMissing();
    $operation = makeOperationTagMissingNode('/users', 'GET', tags: []);
    $context = makeOperationTagMissingContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.tag-missing');
});

it('emits no findings when operation has tags', function (): void {
    $rule = new OperationTagMissing();
    $operation = makeOperationTagMissingNode('/users', 'GET', tags: ['Users']);
    $context = makeOperationTagMissingContext();

    $findings = iterator_to_array(
        $rule->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits findings only for untagged operations in a mixed set', function (): void {
    $rule = new OperationTagMissing();
    $context = makeOperationTagMissingContext();

    $opWithTags = makeOperationTagMissingNode('/users', 'GET', tags: ['Users']);
    $opWithoutTags = makeOperationTagMissingNode('/posts', 'GET', tags: []);

    $findings1 = iterator_to_array(
        $rule->checkOperation($opWithTags, $context),
    );
    $findings2 = iterator_to_array(
        $rule->checkOperation($opWithoutTags, $context),
    );

    $findings = [...$findings1, ...$findings2];

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('/posts');
});

/**
 * Build a minimal OperationNode for testing the OperationTagMissing rule.
 *
 * @param list<string> $tags
 */
function makeOperationTagMissingNode(
    string $path,
    string $method,
    array $tags,
): OperationNode {
    return new OperationNode(
        pathUri: $path,
        method: $method,
        operationId: 'test.operation',
        summary: null,
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
        tags: $tags,
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
        webhook: false,
    );
}

function makeOperationTagMissingContext(): LintContext
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
