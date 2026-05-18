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
use Radiergummi\OpenApi\Core\Lint\Rules\OperationIdMissing;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new OperationIdMissing();

    expect($rule->id())->toBe('operation.id-missing')
        ->and($rule->level())->toBe(1);
});

it('emits no finding when all operations have an operationId', function (): void {
    $operation = makeOperationIdMissingNode('/users', 'GET', operationId: 'users.index');
    $context = makeOperationIdMissingContext();

    $findings = iterator_to_array(
        (new OperationIdMissing())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits a finding when an operation has no operationId', function (): void {
    $operation = makeOperationIdMissingNode('/users', 'GET', operationId: null);
    $context = makeOperationIdMissingContext();

    $findings = iterator_to_array(
        (new OperationIdMissing())->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.id-missing')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('GET')
        ->and($findings[0]->message)->toContain('/users')
        ->and($findings[0]->message)->toContain('no operationId');
});

it('emits a finding per operation missing an operationId', function (): void {
    $context = makeOperationIdMissingContext();

    $op1 = makeOperationIdMissingNode('/users', 'GET', operationId: null);
    $op2 = makeOperationIdMissingNode('/posts', 'POST', operationId: null);

    $findings1 = iterator_to_array(
        (new OperationIdMissing())->checkOperation($op1, $context),
    );
    $findings2 = iterator_to_array(
        (new OperationIdMissing())->checkOperation($op2, $context),
    );

    $findings = [...$findings1, ...$findings2];

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('GET')
        ->and($findings[0]->message)->toContain('/users')
        ->and($findings[1]->message)->toContain('POST')
        ->and($findings[1]->message)->toContain('/posts');
});

it('does not flag operations that have an operationId alongside missing ones', function (): void {
    $context = makeOperationIdMissingContext();

    $opWithId = makeOperationIdMissingNode('/users', 'GET', operationId: 'users.index');
    $opWithoutId = makeOperationIdMissingNode('/users', 'POST', operationId: null);

    $findings1 = iterator_to_array(
        (new OperationIdMissing())->checkOperation($opWithId, $context),
    );
    $findings2 = iterator_to_array(
        (new OperationIdMissing())->checkOperation($opWithoutId, $context),
    );

    $findings = [...$findings1, ...$findings2];

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('POST');
});

/**
 * Build a minimal OperationNode for testing the OperationIdMissing rule.
 */
function makeOperationIdMissingNode(
    string $path,
    string $method,
    ?string $operationId,
): OperationNode {
    return new OperationNode(
        pathUri: $path,
        method: $method,
        operationId: $operationId,
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
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
        webhook: false,
    );
}

function makeOperationIdMissingContext(): LintContext
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
