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
use Radiergummi\OpenApi\Core\Lint\Rules\OperationIdInvalidChars;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new OperationIdInvalidChars();

    expect($rule->id())
        ->toBe('operation.id-invalid-chars')
        ->and($rule->level())
        ->toBe(1);
});

it('emits a finding for an operationId with a space and exclamation mark', function (): void {
    $rule = new OperationIdInvalidChars();
    $context = makeInvalidCharsContext();

    $operation = makeInvalidCharsOperationNode('get projects!', '/projects');

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.id-invalid-chars')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('get projects!');
});

it('emits a finding for an operationId starting with a digit', function (): void {
    $rule = new OperationIdInvalidChars();
    $context = makeInvalidCharsContext();

    $operation = makeInvalidCharsOperationNode('2fa.enable', '/auth/2fa');

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.id-invalid-chars')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('2fa.enable');
});

it('emits no finding for a dot-separated operationId', function (): void {
    $rule = new OperationIdInvalidChars();
    $context = makeInvalidCharsContext();

    $operation = makeInvalidCharsOperationNode('projects.list', '/projects');

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no finding for an operationId with hyphens, underscores and digits', function (): void {
    $rule = new OperationIdInvalidChars();
    $context = makeInvalidCharsContext();

    $operation = makeInvalidCharsOperationNode('projects-list_v2', '/projects');

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no finding when operationId is null', function (): void {
    $rule = new OperationIdInvalidChars();
    $context = makeInvalidCharsContext();

    $operation = new OperationNode(
        pathUri: '/projects',
        method: 'GET',
        operationId: null,
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
    );

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

/**
 * Build an OperationNode with the given operationId and path.
 */
function makeInvalidCharsOperationNode(string $operationId, string $path): OperationNode
{
    return new OperationNode(
        pathUri: $path,
        method: 'GET',
        operationId: $operationId,
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
    );
}

/**
 * Build a minimal LintContext for use in invalid-chars tests.
 */
function makeInvalidCharsContext(): LintContext
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
