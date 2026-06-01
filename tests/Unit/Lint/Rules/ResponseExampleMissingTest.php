<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\ResponseExampleMissing;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new ResponseExampleMissing();

    expect($rule->id())->toBe('response.example-missing')
        ->and($rule->level())->toBe(4);
});

it('emits a finding when a response has no examples and has content', function (): void {
    $rule = new ResponseExampleMissing();
    $response = OperationNodeFactory::makeResponse(
        statusCode: 200,
        schemaRef: '#/components/schemas/UserResource',
    );

    $findings = iterator_to_array(
        $rule->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('response.example-missing')
        ->and($findings[0]->level)->toBe(4)
        ->and($findings[0]->message)->toContain('200');
});

it('emits no finding when a response has examples', function (): void {
    $rule = new ResponseExampleMissing();
    $response = OperationNodeFactory::makeResponse(
        statusCode: 200,
        examples: [OperationNodeFactory::makeExample()],
        schemaRef: '#/components/schemas/UserResource',
    );

    $findings = iterator_to_array(
        $rule->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits no finding for a 204 response with no content', function (): void {
    $rule = new ResponseExampleMissing();
    $response = OperationNodeFactory::makeResponse(statusCode: 204, description: 'No Content');

    $findings = iterator_to_array(
        $rule->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
