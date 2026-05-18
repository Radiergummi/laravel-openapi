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
use Radiergummi\OpenApi\Core\Lint\Rules\ResponseDuplicateStatus;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new ResponseDuplicateStatus();

    expect($rule->id())->toBe('response.duplicate-status')->and($rule->level())->toBe(0);
});

it('emits a finding when an operation has duplicate response status codes', function (): void {
    $rule = new ResponseDuplicateStatus();
    $operation = makeResponseDuplicateOperation(statusCodes: [200, 200, 404]);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, makeResponseDuplicateContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('response.duplicate-status')
        ->and($findings[0]->level)
        ->toBe(0)
        ->and($findings[0]->message)
        ->toContain('200')
        ->and($findings[0]->message)
        ->toContain('2 times');
});

it('emits no findings when all response status codes are unique', function (): void {
    $rule = new ResponseDuplicateStatus();
    $operation = makeResponseDuplicateOperation(statusCodes: [200, 404, 500]);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, makeResponseDuplicateContext()),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when an operation has no responses', function (): void {
    $rule = new ResponseDuplicateStatus();
    $operation = makeResponseDuplicateOperation(statusCodes: []);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, makeResponseDuplicateContext()),
    );

    expect($findings)->toBe([]);
});

it('emits multiple findings for multiple duplicated status codes', function (): void {
    $rule = new ResponseDuplicateStatus();
    $operation = makeResponseDuplicateOperation(statusCodes: [200, 200, 404, 404]);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, makeResponseDuplicateContext()),
    );

    expect($findings)->toHaveCount(2);
});

/**
 * @param list<int> $statusCodes
 */
function makeResponseDuplicateOperation(array $statusCodes): OperationNode
{
    $responses = array_map(
        static fn(int $code): ResponseNode => new ResponseNode(
            statusCode: $code,
            description: "Response $code",
            fields: [],
            examples: [],
            schemaRef: null,
            headers: [],
            links: [],
            raw: null,
        ),
        $statusCodes,
    );

    return new OperationNode(
        pathUri: '/test',
        method: 'GET',
        operationId: 'test.operation',
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
        raw: new OA\Get(['_context' => new Context()]),
    );
}

function makeResponseDuplicateContext(): LintContext
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
