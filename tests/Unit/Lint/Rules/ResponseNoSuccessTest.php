<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\ResponseNoSuccess;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new ResponseNoSuccess();

    expect($rule->id())->toBe('response.no-success')
        ->and($rule->level())->toBe(2);
});

it(
    'emits no finding when an operation has a success response',
    function (string $method, array $statusCodes): void {
        $operation = makeResponseNoSuccessOperation('/users', $method, $statusCodes);

        $findings = iterator_to_array(
            new ResponseNoSuccess()->checkOperation($operation, OperationNodeFactory::emptyContext()),
        );

        expect($findings)->toBe([]);
    },
)->with([
    '200' => ['GET', [200]],
    '201' => ['POST', [201]],
    '204' => ['DELETE', [204]],
    'success + errors' => ['GET', [200, 401, 500]],
]);

it('emits a finding when an operation has only error responses', function (): void {
    $operation = makeResponseNoSuccessOperation('/users', 'GET', [401, 500]);

    $findings = iterator_to_array(
        new ResponseNoSuccess()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('response.no-success')
        ->and($findings[0]->level)->toBe(2)
        ->and($findings[0]->message)->toContain('GET')
        ->and($findings[0]->message)->toContain('/users')
        ->and($findings[0]->message)->toContain('no 2xx');
});

it('skips operations with no responses (caught by response.empty)', function (): void {
    $operation = makeResponseNoSuccessOperation('/empty', 'GET', []);

    $findings = iterator_to_array(
        new ResponseNoSuccess()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits no finding when the only response is a default response', function (): void {
    $operation = OperationNodeFactory::makeOperation(
        pathUri: '/things',
        operationId: null,
        responses: [OperationNodeFactory::makeResponse(statusCode: 'default', description: 'Default response')],
    );

    $findings = iterator_to_array(
        new ResponseNoSuccess()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits a finding per operation missing a success response', function (): void {
    $context = OperationNodeFactory::emptyContext();
    $op1 = makeResponseNoSuccessOperation('/things', 'GET', [404]);
    $op2 = makeResponseNoSuccessOperation('/stuff', 'POST', [422]);

    $findings = [
        ...iterator_to_array(new ResponseNoSuccess()->checkOperation($op1, $context)),
        ...iterator_to_array(new ResponseNoSuccess()->checkOperation($op2, $context)),
    ];

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->message)->toContain('GET')
        ->and($findings[1]->message)->toContain('POST');
});

/**
 * @param list<int> $statusCodes
 */
function makeResponseNoSuccessOperation(string $path, string $method, array $statusCodes): OperationNode
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
