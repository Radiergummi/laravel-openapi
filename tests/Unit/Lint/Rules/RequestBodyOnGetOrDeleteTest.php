<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\Rules\RequestBodyOnGetOrDelete;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new RequestBodyOnGetOrDelete();

    expect($rule->id())->toBe('request-body.on-get-or-delete')
        ->and($rule->severity())->toBe(Severity::Degraded);
});

it(
    'emits a finding when a body-less verb has a request body',
    function (HttpMethod $method): void {
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
            ->and($findings[0]->severity)->toBe(Severity::Degraded)
            ->and($findings[0]->message)->toContain($method->forDisplay());
    },
)->with([HttpMethod::Get, HttpMethod::Delete]);

it(
    'emits no findings when a body-carrying verb has a request body',
    function (HttpMethod $method): void {
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
)->with([HttpMethod::Post, HttpMethod::Put, HttpMethod::Patch]);

it('emits no findings when GET has no request body', function (): void {
    $rule = new RequestBodyOnGetOrDelete();
    $operation = OperationNodeFactory::makeOperation(responses: []);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
