<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\ResponseStatusUnconventional;
use Radiergummi\OpenApi\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new ResponseStatusUnconventional();

    expect($rule->id())->toBe('response.status-unconventional')
        ->and($rule->level())->toBe(3);
});

it(
    'emits no finding when the operation declares a conventional status',
    function (string $method, array $statusCodes, string $targetStatus): void {
        $response = makeResponseForStatusTest('/users', $method, $statusCodes, $targetStatus);

        $findings = iterator_to_array(
            new ResponseStatusUnconventional()->checkResponse($response, OperationNodeFactory::emptyContext()),
        );

        expect($findings)->toBe([]);
    },
)->with([
    'POST 201' => ['POST', ['201'], '201'],
    'DELETE 204' => ['DELETE', ['204'], '204'],
    'GET 200' => ['GET', ['200'], '200'],
    'POST with both 200 and 201' => ['POST', ['200', '201'], '200'],
    'DELETE with both 200 and 204' => ['DELETE', ['200', '204'], '200'],
]);

it(
    'emits no finding when a 200 response carries a body schema for verbs that usually return 201 / 204',
    function (string $method): void {
        $response = OperationNodeFactory::makeResponse(statusCode: '200', description: null, schemaRef: 'SomeResource');
        OperationNodeFactory::makeOperation(pathUri: '/users/1', method: $method, responses: [$response]);

        $findings = iterator_to_array(
            new ResponseStatusUnconventional()->checkResponse($response, OperationNodeFactory::emptyContext()),
        );

        expect($findings)->toBe([]);
    },
)->with([
    'DELETE returning the deleted resource' => ['DELETE'],
    'POST returning the created resource' => ['POST'],
]);

it(
    'emits a finding when an operation only declares an unconventional 2xx status',
    function (string $method, array $statusCodes, string $expectedConventional): void {
        $response = makeResponseForStatusTest('/users', $method, $statusCodes, '200');

        $findings = iterator_to_array(
            new ResponseStatusUnconventional()->checkResponse($response, OperationNodeFactory::emptyContext()),
        );

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->ruleId)->toBe('response.status-unconventional')
            ->and($findings[0]->level)->toBe(3)
            ->and($findings[0]->message)->toContain($method)
            ->and($findings[0]->message)->toContain($expectedConventional);
    },
)->with([
    'POST 200 (expects 201)' => ['POST', ['200', '422'], '201'],
    'DELETE 200 (expects 204)' => ['DELETE', ['200'], '204'],
]);

/**
 * Build an operation with the given status codes and return the response
 * matching `$targetStatus` (falling back to the first response).
 *
 * @param list<string> $statusCodes
 */
function makeResponseForStatusTest(
    string $pathUri,
    string $method,
    array $statusCodes,
    string $targetStatus = '200',
): ResponseNode {
    $responses = array_map(
        static fn(string $code) => OperationNodeFactory::makeResponse(statusCode: $code, description: null),
        $statusCodes,
    );

    OperationNodeFactory::makeOperation(pathUri: $pathUri, method: $method, responses: $responses);

    foreach ($responses as $response) {
        if ((string) $response->statusCode === $targetStatus) {
            return $response;
        }
    }

    return $responses[0];
}
