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
use Radiergummi\OpenApi\Core\Lint\Rules\ResponseNoError;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new ResponseNoError();

    expect($rule->id())
        ->toBe('response.no-error')
        ->and($rule->level())
        ->toBe(1);
});

it('emits no finding when an operation has a 4xx response', function (): void {
    $operation = makeResponseNoErrorOperation('/users', 'GET', [200, 404]);
    $context = makeResponseNoErrorContext();

    $findings = iterator_to_array(
        (new ResponseNoError())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when an operation has a 5xx response', function (): void {
    $operation = makeResponseNoErrorOperation('/users', 'GET', [200, 500]);
    $context = makeResponseNoErrorContext();

    $findings = iterator_to_array(
        (new ResponseNoError())->checkOperation($operation, $context),
    );

    expect($findings)->toBe([]);
});

it(
    'emits no finding when an operation has both success and error responses',
    function (): void {
        $operation = makeResponseNoErrorOperation('/users', 'POST', [201, 400, 422, 500]);
        $context = makeResponseNoErrorContext();

        $findings = iterator_to_array(
            (new ResponseNoError())->checkOperation($operation, $context),
        );

        expect($findings)->toBe([]);
    },
);

it(
    'emits a finding when an operation has only success responses',
    function (): void {
        $operation = makeResponseNoErrorOperation('/users', 'GET', [200]);
        $context = makeResponseNoErrorContext();

        $findings = iterator_to_array(
            (new ResponseNoError())->checkOperation($operation, $context),
        );

        expect($findings)
            ->toHaveCount(1)
            ->and($findings[0]->ruleId)
            ->toBe('response.no-error')
            ->and($findings[0]->level)
            ->toBe(1)
            ->and($findings[0]->message)
            ->toContain('GET')
            ->and($findings[0]->message)
            ->toContain('/users')
            ->and($findings[0]->message)
            ->toContain('no error response');
    },
);

it(
    'skips operations with no responses (caught by response.empty)',
    function (): void {
        $operation = makeResponseNoErrorOperation('/empty', 'GET', []);
        $context = makeResponseNoErrorContext();

        $findings = iterator_to_array(
            (new ResponseNoError())->checkOperation($operation, $context),
        );

        expect($findings)->toBe([]);
    },
);

it(
    'emits a finding per operation missing an error response',
    function (): void {
        $context = makeResponseNoErrorContext();

        $op1 = makeResponseNoErrorOperation('/things', 'GET', [200]);
        $op2 = makeResponseNoErrorOperation('/stuff', 'POST', [201]);

        $findings1 = iterator_to_array(
            (new ResponseNoError())->checkOperation($op1, $context),
        );
        $findings2 = iterator_to_array(
            (new ResponseNoError())->checkOperation($op2, $context),
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
function makeResponseNoErrorOperation(
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

function makeResponseNoErrorContext(): LintContext
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
