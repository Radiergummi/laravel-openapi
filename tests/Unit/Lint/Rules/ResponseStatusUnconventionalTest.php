<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\ResponseStatusUnconventional;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

function makeStatusUnconventionalContext(): LintContext
{
    $spec = new OA\OpenApi(['_context' => new Context()]);

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}

/**
 * Build an operation with the given status codes and return the ResponseNode
 * for the given target status code.
 *
 * @param list<string> $statusCodes
 */
function makeResponseForStatusTest(
    string $pathUri,
    string $method,
    array $statusCodes,
    string $targetStatus = '200',
): ?ResponseNode {
    $responses = [];

    foreach ($statusCodes as $status) {
        $responses[] = new ResponseNode(
            statusCode: $status,
            description: null,
            fields: [],
            examples: [],
            schemaRef: null,
            headers: [],
            links: [],
            raw: null,
        );
    }

    $operationClass = match ($method) {
        'GET' => OA\Get::class,
        'POST' => OA\Post::class,
        'PUT' => OA\Put::class,
        'PATCH' => OA\Patch::class,
        'DELETE' => OA\Delete::class,
        default => OA\Get::class,
    };

    $operation = new OperationNode(
        pathUri: $pathUri,
        method: $method,
        operationId: null,
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: $responses,
        security: [],
        tags: [],
        descriptor: null,
        raw: new $operationClass(['_context' => new Context()]),
    );

    foreach ($responses as $response) {
        $response->linkParent($operation);
    }

    // Return the response matching the target status
    foreach ($responses as $response) {
        if ((string) $response->statusCode === $targetStatus) {
            return $response;
        }
    }

    return $responses[0] ?? null;
}

it('reports its id and level', function (): void {
    $rule = new ResponseStatusUnconventional();

    expect($rule->id())->toBe('response.status-unconventional')->and($rule->level())->toBe(3);
});

it('emits no finding when a POST operation returns 201', function (): void {
    $response = makeResponseForStatusTest('/users', 'POST', ['201']);
    $context = makeStatusUnconventionalContext();

    $findings = iterator_to_array(
        new ResponseStatusUnconventional()->checkResponse($response, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when a DELETE operation returns 204', function (): void {
    $response = makeResponseForStatusTest('/users/1', 'DELETE', ['204']);
    $context = makeStatusUnconventionalContext();

    $findings = iterator_to_array(
        new ResponseStatusUnconventional()->checkResponse($response, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when a GET operation returns 200', function (): void {
    $response = makeResponseForStatusTest('/users', 'GET', ['200']);
    $context = makeStatusUnconventionalContext();

    $findings = iterator_to_array(
        new ResponseStatusUnconventional()->checkResponse($response, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when a POST operation has both 200 and 201', function (): void {
    $response = makeResponseForStatusTest('/users', 'POST', ['200', '201']);
    $context = makeStatusUnconventionalContext();

    $findings = iterator_to_array(
        new ResponseStatusUnconventional()->checkResponse($response, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when a DELETE operation has both 200 and 204', function (): void {
    $response = makeResponseForStatusTest('/users/1', 'DELETE', ['200', '204']);
    $context = makeStatusUnconventionalContext();

    $findings = iterator_to_array(
        new ResponseStatusUnconventional()->checkResponse($response, $context),
    );

    expect($findings)->toBe([]);
});

it('emits a finding when a POST operation only declares 200', function (): void {
    $response = makeResponseForStatusTest('/users', 'POST', ['200', '422']);
    $context = makeStatusUnconventionalContext();

    $findings = iterator_to_array(
        new ResponseStatusUnconventional()->checkResponse($response, $context),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('response.status-unconventional')
        ->and($findings[0]->level)
        ->toBe(3)
        ->and($findings[0]->message)
        ->toContain('POST')
        ->and($findings[0]->message)
        ->toContain('/users')
        ->and($findings[0]->message)
        ->toContain('201');
});

it('emits no finding when a DELETE operation returns 200 with a body schema (returning the deleted resource)', function (): void {
    $responses = [];
    $responses[] = new ResponseNode(
        statusCode: '200',
        description: null,
        fields: [],
        examples: [],
        schemaRef: 'DeletedResource',  // has a $ref schema
        headers: [],
        links: [],
        raw: null,
    );

    $operation = new OperationNode(
        pathUri: '/users/1',
        method: 'DELETE',
        operationId: null,
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: $responses,
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Delete(['_context' => new Context()]),
    );

    foreach ($responses as $response) {
        $response->linkParent($operation);
    }

    $context = makeStatusUnconventionalContext();

    $findings = iterator_to_array(
        new ResponseStatusUnconventional()->checkResponse($responses[0], $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when a POST operation returns 200 with a body schema (returning a resource)', function (): void {
    $responses = [];
    $responses[] = new ResponseNode(
        statusCode: '200',
        description: null,
        fields: [],
        examples: [],
        schemaRef: 'CreatedResource',
        headers: [],
        links: [],
        raw: null,
    );

    $operation = new OperationNode(
        pathUri: '/users',
        method: 'POST',
        operationId: null,
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: $responses,
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Post(['_context' => new Context()]),
    );

    foreach ($responses as $response) {
        $response->linkParent($operation);
    }

    $context = makeStatusUnconventionalContext();

    $findings = iterator_to_array(
        new ResponseStatusUnconventional()->checkResponse($responses[0], $context),
    );

    expect($findings)->toBe([]);
});

it('emits a finding when a DELETE operation only declares 200', function (): void {
    $response = makeResponseForStatusTest('/users/1', 'DELETE', ['200']);
    $context = makeStatusUnconventionalContext();

    $findings = iterator_to_array(
        new ResponseStatusUnconventional()->checkResponse($response, $context),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('response.status-unconventional')
        ->and($findings[0]->message)
        ->toContain('DELETE')
        ->and($findings[0]->message)
        ->toContain('204');
});
