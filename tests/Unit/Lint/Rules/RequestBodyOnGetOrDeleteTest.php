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
use Radiergummi\OpenApi\Core\Lint\Rules\RequestBodyOnGetOrDelete;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\RequestBodyNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new RequestBodyOnGetOrDelete();

    expect($rule->id())->toBe('request-body.on-get-or-delete')->and($rule->level())->toBe(1);
});

it('emits a finding when GET has a request body', function (): void {
    $rule = new RequestBodyOnGetOrDelete();
    $operation = makeRequestBodyOperation(method: 'GET', withRequestBody: true);

    $findings = iterator_to_array($rule->checkOperation($operation, makeRequestBodyContext()));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('request-body.on-get-or-delete')
        ->and($findings[0]->level)
        ->toBe(1)
        ->and($findings[0]->message)
        ->toContain('GET');
});

it('emits a finding when DELETE has a request body', function (): void {
    $rule = new RequestBodyOnGetOrDelete();
    $operation = makeRequestBodyOperation(method: 'DELETE', withRequestBody: true);

    $findings = iterator_to_array($rule->checkOperation($operation, makeRequestBodyContext()));

    expect($findings)->toHaveCount(1)->and($findings[0]->message)->toContain('DELETE');
});

it('emits no findings when POST has a request body', function (): void {
    $rule = new RequestBodyOnGetOrDelete();
    $operation = makeRequestBodyOperation(method: 'POST', withRequestBody: true);

    $findings = iterator_to_array($rule->checkOperation($operation, makeRequestBodyContext()));

    expect($findings)->toBe([]);
});

it('emits no findings when PUT has a request body', function (): void {
    $rule = new RequestBodyOnGetOrDelete();
    $operation = makeRequestBodyOperation(method: 'PUT', withRequestBody: true);

    $findings = iterator_to_array($rule->checkOperation($operation, makeRequestBodyContext()));

    expect($findings)->toBe([]);
});

it('emits no findings when PATCH has a request body', function (): void {
    $rule = new RequestBodyOnGetOrDelete();
    $operation = makeRequestBodyOperation(method: 'PATCH', withRequestBody: true);

    $findings = iterator_to_array($rule->checkOperation($operation, makeRequestBodyContext()));

    expect($findings)->toBe([]);
});

it('emits no findings when GET has no request body', function (): void {
    $rule = new RequestBodyOnGetOrDelete();
    $operation = makeRequestBodyOperation(method: 'GET', withRequestBody: false);

    $findings = iterator_to_array($rule->checkOperation($operation, makeRequestBodyContext()));

    expect($findings)->toBe([]);
});

function makeRequestBodyOperation(string $method, bool $withRequestBody): OperationNode
{
    $requestBody = $withRequestBody
        ? new RequestBodyNode(
            contentTypes: ['application/json'],
            required: true,
            fields: [],
            examples: [],
            schemaRef: null,
            description: null,
            raw: null,
        )
        : null;

    $rawClass = match ($method) {
        'GET' => OA\Get::class,
        'POST' => OA\Post::class,
        'PUT' => OA\Put::class,
        'PATCH' => OA\Patch::class,
        'DELETE' => OA\Delete::class,
        default => OA\Get::class,
    };

    return new OperationNode(
        pathUri: '/test',
        method: $method,
        operationId: 'test.action',
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: $requestBody,
        responses: [],
        security: [],
        tags: [],
        descriptor: null,
        raw: new $rawClass(['_context' => new Context()]),
    );
}

function makeRequestBodyContext(): LintContext
{
    $ctx = new Context();
    $spec = new OA\OpenApi([
        'openapi' => '3.1.0',
        'info' => new OA\Info(['title' => 'Test', 'version' => '0.1', '_context' => $ctx]),
    ]);

    $index = new TreeIndex(
        operationsByOperationId: [],
        operationsByRouteKey: [],
        componentsByName: [],
        referencedComponents: [],
        registeredScopes: [],
        knownRuleIds: [],
    );

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: $index,
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}
