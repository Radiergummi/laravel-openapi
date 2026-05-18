<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\OperationIdNamingInconsistent;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new OperationIdNamingInconsistent();

    expect($rule->id())
        ->toBe('operation.id-naming-inconsistent')
        ->and($rule->level())
        ->toBe(3);
});

it('emits no finding for a valid dot-separated operationId', function (): void {
    $rule = new OperationIdNamingInconsistent();
    $context = makeNamingContext();

    $operation = makeNamingOperationNode('api.v0.users.index', '/users');

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no finding for a single-segment operationId', function (): void {
    $rule = new OperationIdNamingInconsistent();
    $context = makeNamingContext();

    $operation = makeNamingOperationNode('users', '/users');

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits a finding for camelCase operationId', function (): void {
    $rule = new OperationIdNamingInconsistent();
    $context = makeNamingContext();

    $operation = makeNamingOperationNode('getUsers', '/users');

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('operation.id-naming-inconsistent')
        ->and($findings[0]->level)
        ->toBe(3)
        ->and($findings[0]->message)
        ->toContain('getUsers');
});

it('emits a finding for dash-separated operationId', function (): void {
    $rule = new OperationIdNamingInconsistent();
    $context = makeNamingContext();

    $operation = makeNamingOperationNode('get-users', '/users');

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->message)
        ->toContain('get-users');
});

it('emits a finding for operationId starting with uppercase', function (): void {
    $rule = new OperationIdNamingInconsistent();
    $context = makeNamingContext();

    $operation = makeNamingOperationNode('Users.index', '/users');

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1);
});

it('skips operations without an operationId', function (): void {
    $rule = new OperationIdNamingInconsistent();
    $context = makeNamingContext();

    $operation = new OperationNode(
        pathUri: '/users',
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

it('accepts operationId with numeric segments', function (): void {
    $rule = new OperationIdNamingInconsistent();
    $context = makeNamingContext();

    $operation = makeNamingOperationNode('api.v0.users.index', '/api/v0/users');

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('kebab case: passes a valid kebab operationId', function (): void {
    $rule = new OperationIdNamingInconsistent(IdentifierCase::Kebab);
    $context = makeNamingContext();

    $findings = iterator_to_array($rule->checkOperation(makeNamingOperationNode('api-v0-projects', '/projects'), $context));

    expect($findings)->toBe([]);
});

it('kebab case: flags a dot-separated operationId as inconsistent', function (): void {
    $rule = new OperationIdNamingInconsistent(IdentifierCase::Kebab);
    $context = makeNamingContext();

    $findings = iterator_to_array($rule->checkOperation(makeNamingOperationNode('api.v0.projects', '/projects'), $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.id-naming-inconsistent')
        ->and($findings[0]->message)->toContain('api.v0.projects')
        ->and($findings[0]->message)->toContain('kebab-case');
});

it('default (dot) case still passes valid dot-separated operationId', function (): void {
    $rule = new OperationIdNamingInconsistent();
    $context = makeNamingContext();

    $findings = iterator_to_array($rule->checkOperation(makeNamingOperationNode('api.v0.projects.index', '/projects'), $context));

    expect($findings)->toBe([]);
});

it('default (dot) case flags a kebab operationId', function (): void {
    $rule = new OperationIdNamingInconsistent();
    $context = makeNamingContext();

    $findings = iterator_to_array($rule->checkOperation(makeNamingOperationNode('api-v0-projects', '/projects'), $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('dot-separated lowercase');
});

/**
 * Build an OperationNode with the given operationId and path.
 */
function makeNamingOperationNode(string $operationId, string $path): OperationNode
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
 * Build a minimal LintContext for use in naming tests.
 */
function makeNamingContext(): LintContext
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
