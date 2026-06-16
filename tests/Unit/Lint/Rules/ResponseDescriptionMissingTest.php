<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\ResponseDescriptionMissing;
use Radiergummi\OpenApi\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function makeResponseUnderOperation(?string $description): ResponseNode
{
    $response = OperationNodeFactory::makeResponse(description: $description);
    OperationNodeFactory::makeOperation(responses: [$response]);

    return $response;
}

it('has the correct rule id and level', function (): void {
    $rule = new ResponseDescriptionMissing();

    expect($rule->id())
        ->toBe('response.description-missing')
        ->and($rule->severity())->toBe(Severity::Broken);
});

it('emits a finding when a response has a missing or blank description', function (?string $description): void {
    $rule = new ResponseDescriptionMissing();
    $response = makeResponseUnderOperation($description);

    $findings = iterator_to_array(
        $rule->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('response.description-missing')
        ->and($findings[0]->severity)->toBe(Severity::Broken)
        ->and($findings[0]->message)->toContain('200')
        ->and($findings[0]->message)->toContain('GET');
})->with([
    'null' => [null],
    'empty string' => [''],
    'whitespace only' => ['   '],
]);

it('emits no findings when a response has a description', function (): void {
    $rule = new ResponseDescriptionMissing();
    $response = makeResponseUnderOperation('Successful response');

    $findings = iterator_to_array(
        $rule->checkResponse($response, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
