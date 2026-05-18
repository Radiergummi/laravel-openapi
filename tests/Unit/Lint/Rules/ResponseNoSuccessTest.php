<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\ResponseNoSuccess;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new ResponseNoSuccess();

    expect($rule->id())
        ->toBe('response.no-success')
        ->and($rule->level())
        ->toBe(2);
});

it('emits no finding when an operation has a 200 response', function (): void {
    $operation = makeResponseNoSuccessOperation('/users', 'GET', [200]);
    $context = makeResponseNoSuccessContext();

    $findings = iterator_to_array(
        (new ResponseNoSuccess())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when an operation has a 201 response', function (): void {
    $operation = makeResponseNoSuccessOperation('/users', 'POST', [201]);
    $context = makeResponseNoSuccessContext();

    $findings = iterator_to_array(
        (new ResponseNoSuccess())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when an operation has a 204 response', function (): void {
    $operation = makeResponseNoSuccessOperation('/users/1', 'DELETE', [204]);
    $context = makeResponseNoSuccessContext();

    $findings = iterator_to_array(
        (new ResponseNoSuccess())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it(
    'emits no finding when an operation has both error and success responses',
    function (): void {
        $operation = makeResponseNoSuccessOperation('/users', 'GET', [200, 401, 500]);
        $context = makeResponseNoSuccessContext();

        $findings = iterator_to_array(
            (new ResponseNoSuccess())->checkOperation($operation, $context),
        );

        expect($findings)->toBe([]);
    },
);

it(
    'emits a finding when an operation has only error responses',
    function (): void {
        $operation = makeResponseNoSuccessOperation('/users', 'GET', [401, 500]);
        $context = makeResponseNoSuccessContext();

        $findings = iterator_to_array(
            (new ResponseNoSuccess())->checkOperation($operation, $context),
        );

        expect($findings)
            ->toHaveCount(1)
            ->and($findings[0]->ruleId)
            ->toBe('response.no-success')
            ->and($findings[0]->level)
            ->toBe(2)
            ->and($findings[0]->message)
            ->toContain('GET')
            ->and($findings[0]->message)
            ->toContain('/users')
            ->and($findings[0]->message)
            ->toContain('no 2xx');
    },
);

it(
    'skips operations with no responses (caught by response.empty)',
    function (): void {
        $operation = makeResponseNoSuccessOperation('/empty', 'GET', []);
        $context = makeResponseNoSuccessContext();

        $findings = iterator_to_array(
            (new ResponseNoSuccess())->checkOperation($operation, $context),
        );

        expect($findings)->toBe([]);
    },
);

it(
    'emits no finding when the only response is a default response',
    function (): void {
        $context = makeResponseNoSuccessContext();

        $responses = [
            new ResponseNode(
                statusCode: 'default',
                description: 'Default response',
                fields: [],
                examples: [],
                schemaRef: null,
                headers: [],
                links: [],
                raw: null,
            ),
        ];

        $operation = new OperationNode(
            pathUri: '/things',
            method: 'GET',
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
            raw: new OA\Get(['_context' => new Context()]),
            webhook: false,
        );

        $findings = iterator_to_array(
            (new ResponseNoSuccess())->checkOperation($operation, $context),
        );

        expect($findings)->toBe([]);
    },
);

it(
    'emits a finding per operation missing a success response',
    function (): void {
        $context = makeResponseNoSuccessContext();

        $op1 = makeResponseNoSuccessOperation('/things', 'GET', [404]);
        $op2 = makeResponseNoSuccessOperation('/stuff', 'POST', [422]);

        $findings1 = iterator_to_array(
            (new ResponseNoSuccess())->checkOperation($op1, $context),
        );
        $findings2 = iterator_to_array(
            (new ResponseNoSuccess())->checkOperation($op2, $context),
        );

        $findings = [...$findings1, ...$findings2];

        expect($findings)
            ->toHaveCount(2)
            ->and($findings[0]->message)
            ->toContain('GET')
            ->and($findings[1]->message)
            ->toContain('POST');
    },
);

/**
 * Build a minimal OperationNode with the given response status codes.
 *
 * @param list<int> $statusCodes
 */
function makeResponseNoSuccessOperation(
    string $path,
    string $method,
    array $statusCodes,
): OperationNode {
    $responses = array_map(
        static fn(int $code): ResponseNode => new ResponseNode(
            statusCode: $code,
            description: 'Response',
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
        pathUri: $path,
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
        raw: new OA\Get(['_context' => new Context()]),
        webhook: false,
    );
}

function makeResponseNoSuccessContext(): LintContext
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
