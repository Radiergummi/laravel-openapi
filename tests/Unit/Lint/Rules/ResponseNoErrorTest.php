<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\Rules\ResponseNoError;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new ResponseNoError();

    expect($rule->id())->toBe('response.no-error')
        ->and($rule->severity())->toBe(Severity::Degraded);
});

it(
    'emits no finding when an operation has an error response',
    function (HttpMethod $method, array $statusCodes): void {
        $operation = makeResponseNoErrorOperation('/users', $method, $statusCodes);

        $findings = iterator_to_array(
            new ResponseNoError()->checkOperation($operation, OperationNodeFactory::emptyContext()),
        );

        expect($findings)->toBe([]);
    },
)->with([
    '4xx alongside success' => [HttpMethod::Get, [200, 404]],
    '5xx alongside success' => [HttpMethod::Get, [200, 500]],
    'mixed success + 4xx + 5xx' => [HttpMethod::Post, [201, 400, 422, 500]],
]);

it('emits a finding when an operation has only success responses', function (): void {
    $operation = makeResponseNoErrorOperation('/users', HttpMethod::Get, [200]);

    $findings = iterator_to_array(
        new ResponseNoError()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('response.no-error')
        ->and($findings[0]->severity)->toBe(Severity::Degraded)
        ->and($findings[0]->message)->toContain('GET')
        ->and($findings[0]->message)->toContain('/users')
        ->and($findings[0]->message)->toContain('no error response');
});

it('skips operations with no responses (caught by response.empty)', function (): void {
    $operation = makeResponseNoErrorOperation('/empty', HttpMethod::Get, []);

    $findings = iterator_to_array(
        new ResponseNoError()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits a finding per operation missing an error response', function (): void {
    $context = OperationNodeFactory::emptyContext();
    $op1 = makeResponseNoErrorOperation('/things', HttpMethod::Get, [200]);
    $op2 = makeResponseNoErrorOperation('/stuff', HttpMethod::Post, [201]);

    $findings = [
        ...iterator_to_array(new ResponseNoError()->checkOperation($op1, $context)),
        ...iterator_to_array(new ResponseNoError()->checkOperation($op2, $context)),
    ];

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('GET')
        ->and($findings[1]->message)->toContain('POST');
});

/**
 * @param list<int> $statusCodes
 *
 * @throws LogicException
 */
function makeResponseNoErrorOperation(string $path, HttpMethod $method, array $statusCodes): OperationNode
{
    return OperationNodeFactory::makeOperation(
        pathUri: $path,
        method: $method,
        operationId: null,
        responses: array_map(
            static fn(int $code) => OperationNodeFactory::makeResponse(statusCode: $code, description: 'Response'),
            $statusCodes,
        ),
    );
}
