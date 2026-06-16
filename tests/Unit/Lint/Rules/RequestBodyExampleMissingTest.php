<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\RequestBodyExampleMissing;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new RequestBodyExampleMissing();

    expect($rule->id())
        ->toBe('request-body.example-missing')
        ->and($rule->level())->toBe(4);
});

it('emits a finding when a request body has no examples', function (): void {
    $rule = new RequestBodyExampleMissing();
    $requestBody = OperationNodeFactory::makeRequestBody(description: 'The payload.');

    $findings = iterator_to_array(
        $rule->checkRequestBody($requestBody, OperationNodeFactory::emptyContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('request-body.example-missing')
        ->and($findings[0]->level)->toBe(4);
});

it('emits no finding when a request body has examples', function (): void {
    $rule = new RequestBodyExampleMissing();
    $requestBody = OperationNodeFactory::makeRequestBody(
        examples: [OperationNodeFactory::makeExample(value: ['name' => 'Acme Corp'])],
        description: 'The payload.',
    );

    $findings = iterator_to_array(
        $rule->checkRequestBody($requestBody, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
