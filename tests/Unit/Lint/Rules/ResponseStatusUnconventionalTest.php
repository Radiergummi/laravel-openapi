<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\Rules\ResponseStatusUnconventional;
use Radiergummi\OpenApi\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new ResponseStatusUnconventional();

    expect($rule->id)->toBe('response.status-unconventional')
        ->and($rule->severity)->toBe(Severity::Inconsistent);
});

it(
    'emits no finding when the operation declares a conventional status',
    function (HttpMethod $method, array $statusCodes, string $targetStatus): void {
        $response = makeResponseForStatusTest('/users', $method, $statusCodes, $targetStatus);

        $findings = iterator_to_array(
            new ResponseStatusUnconventional()->checkResponse($response, OperationNodeFactory::emptyContext()),
        );

        expect($findings)->toBe([]);
    },
)->with([
    'POST 201' => [HttpMethod::Post, ['201'], '201'],
    'DELETE 204' => [HttpMethod::Delete, ['204'], '204'],
    'GET 200' => [HttpMethod::Get, ['200'], '200'],
    'POST with both 200 and 201' => [HttpMethod::Post, ['200', '201'], '200'],
    'DELETE with both 200 and 204' => [HttpMethod::Delete, ['200', '204'], '200'],
]);

it(
    'emits no finding when a 200 response carries a body schema for verbs that usually return 201 / 204',
    function (HttpMethod $method): void {
        $response = OperationNodeFactory::makeResponse(statusCode: '200', description: null, schemaRef: 'SomeResource');
        OperationNodeFactory::makeOperation(pathUri: '/users/1', method: $method, responses: [$response]);

        $findings = iterator_to_array(
            new ResponseStatusUnconventional()->checkResponse($response, OperationNodeFactory::emptyContext()),
        );

        expect($findings)->toBe([]);
    },
)->with([
    'DELETE returning the deleted resource' => [HttpMethod::Delete],
    'POST returning the created resource' => [HttpMethod::Post],
]);

it(
    'emits a finding when an operation only declares an unconventional 2xx status',
    function (HttpMethod $method, array $statusCodes, string $expectedConventional): void {
        $response = makeResponseForStatusTest('/users', $method, $statusCodes, '200');

        $findings = iterator_to_array(
            new ResponseStatusUnconventional()->checkResponse($response, OperationNodeFactory::emptyContext()),
        );

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->ruleId)->toBe('response.status-unconventional')
            ->and($findings[0]->severity)->toBe(Severity::Inconsistent)
            ->and($findings[0]->message)->toContain($method->forDisplay())
            ->and($findings[0]->message)->toContain($expectedConventional);
    },
)->with([
    'POST 200 (expects 201)' => [HttpMethod::Post, ['200', '422'], '201'],
    'DELETE 200 (expects 204)' => [HttpMethod::Delete, ['200'], '204'],
]);

/**
 * Build an operation with the given status codes and return the response
 * matching `$targetStatus` (falling back to the first response).
 *
 * @param list<string> $statusCodes
 *
 * @throws LogicException
 */
function makeResponseForStatusTest(
    string $pathUri,
    HttpMethod $method,
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
