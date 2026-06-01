<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\RequestBodyDescriptionMissing;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new RequestBodyDescriptionMissing();

    expect($rule->id())->toBe('request-body.description-missing')
        ->and($rule->level())->toBe(2);
});

it('emits a finding when a request body has a missing or blank description', function (?string $description): void {
    $rule = new RequestBodyDescriptionMissing();
    $requestBody = OperationNodeFactory::makeRequestBody(description: $description);

    $findings = iterator_to_array(
        $rule->checkRequestBody($requestBody, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('request-body.description-missing')
        ->and($findings[0]->level)->toBe(2);
})->with([
    'null'             => [null],
    'empty string'     => [''],
    'whitespace only'  => ['   '],
]);

it('emits no findings when a request body has a description', function (): void {
    $rule = new RequestBodyDescriptionMissing();
    $requestBody = OperationNodeFactory::makeRequestBody(
        description: 'The payload to create a new project.',
    );

    $findings = iterator_to_array(
        $rule->checkRequestBody($requestBody, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
