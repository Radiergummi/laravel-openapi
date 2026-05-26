<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\Rules\ResponseSuccessEmptyBody;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new ResponseSuccessEmptyBody();

    expect($rule->id())->toBe('response.success-empty-body')
        ->and($rule->level())->toBe(2);
});

it('emits a finding for a 200 response with no body schema', function (): void {
    $response = OperationNodeFactory::makeResponse(statusCode: 200, fields: [], schemaRef: null);
    OperationNodeFactory::makeOperation(pathUri: '/users', method: 'GET', responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('response.success-empty-body')
        ->and($findings[0]->message)->toContain('200')
        ->and($findings[0]->message)->toContain('GET /users');
});

it('does not flag a 200 response that has an inline schema', function (): void {
    $field = OperationNodeFactory::makeField(name: 'id', type: 'integer');
    $response = OperationNodeFactory::makeResponse(statusCode: 200, fields: [$field]);
    OperationNodeFactory::makeOperation(pathUri: '/users/1', method: 'GET', responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('does not flag a 200 response that references a component schema', function (): void {
    $response = OperationNodeFactory::makeResponse(statusCode: 200, schemaRef: 'User');
    OperationNodeFactory::makeOperation(pathUri: '/users/1', method: 'GET', responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('skips bodiless success codes', function (int $statusCode): void {
    $response = OperationNodeFactory::makeResponse(statusCode: $statusCode);
    OperationNodeFactory::makeOperation(pathUri: '/things', method: 'DELETE', responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    '204' => 204,
    '205' => 205,
    '304' => 304,
]);

it('skips non-2xx responses', function (int $statusCode): void {
    $response = OperationNodeFactory::makeResponse(statusCode: $statusCode);
    OperationNodeFactory::makeOperation(pathUri: '/x', method: 'GET', responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    '404' => 404,
    '500' => 500,
]);

it('skips HEAD responses (HEAD bodies are intentionally suppressed)', function (): void {
    $response = OperationNodeFactory::makeResponse(statusCode: 200);
    OperationNodeFactory::makeOperation(pathUri: '/users', method: 'HEAD', responses: [$response]);

    $findings = iterator_to_array(
        new ResponseSuccessEmptyBody()->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
