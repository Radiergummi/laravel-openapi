<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\RequestBodyOnGetOrDelete;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new RequestBodyOnGetOrDelete();

    expect($rule->id())->toBe('request-body.on-get-or-delete')
        ->and($rule->level())->toBe(1);
});

it(
    'emits a finding when a body-less verb has a request body',
    function (string $method): void {
        $rule = new RequestBodyOnGetOrDelete();
        $operation = OperationNodeFactory::makeOperation(
            method: $method,
            requestBody: OperationNodeFactory::makeRequestBody(),
            responses: [],
        );

        $findings = iterator_to_array(
            $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
        );

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->ruleId)->toBe('request-body.on-get-or-delete')
            ->and($findings[0]->level)->toBe(1)
            ->and($findings[0]->message)->toContain($method);
    },
)->with(['GET', 'DELETE']);

it(
    'emits no findings when a body-carrying verb has a request body',
    function (string $method): void {
        $rule = new RequestBodyOnGetOrDelete();
        $operation = OperationNodeFactory::makeOperation(
            method: $method,
            requestBody: OperationNodeFactory::makeRequestBody(),
            responses: [],
        );

        $findings = iterator_to_array(
            $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
        );

        expect($findings)->toBe([]);
    },
)->with(['POST', 'PUT', 'PATCH']);

it('emits no findings when GET has no request body', function (): void {
    $rule = new RequestBodyOnGetOrDelete();
    $operation = OperationNodeFactory::makeOperation(method: 'GET', responses: []);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
