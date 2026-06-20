<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\RequestBodyNoContent;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports the correct id and level', function (): void {
    $rule = new RequestBodyNoContent();

    expect($rule->id)
        ->toBe('request-body.no-content')
        ->and($rule->severity)->toBe(Severity::Degraded);
});

it('emits a finding when a request body has no media-type entries', function (): void {
    $rule = new RequestBodyNoContent();
    $requestBody = OperationNodeFactory::makeRequestBody(contentTypes: []);

    $findings = iterator_to_array(
        $rule->checkRequestBody($requestBody, OperationNodeFactory::emptyContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('request-body.no-content')
        ->and($findings[0]->severity)->toBe(Severity::Degraded);
});

it(
    'emits no finding when a request body declares a content type',
    function (string $contentType): void {
        $rule = new RequestBodyNoContent();
        $requestBody = OperationNodeFactory::makeRequestBody(contentTypes: [$contentType]);

        $findings = iterator_to_array(
            $rule->checkRequestBody($requestBody, OperationNodeFactory::emptyContext()),
        );

        expect($findings)->toBe([]);
    },
)->with([
    'application/json' => ['application/json'],
    'multipart/form-data' => ['multipart/form-data'],
]);
