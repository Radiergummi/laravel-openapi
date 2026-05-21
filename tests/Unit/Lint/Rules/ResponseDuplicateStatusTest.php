<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\Rules\ResponseDuplicateStatus;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new ResponseDuplicateStatus();

    expect($rule->id())->toBe('response.duplicate-status')
        ->and($rule->level())->toBe(0);
});

it('emits a finding when an operation has duplicate response status codes', function (): void {
    $rule = new ResponseDuplicateStatus();
    $operation = OperationNodeFactory::makeOperation(responses: [
        OperationNodeFactory::makeResponse(statusCode: 200),
        OperationNodeFactory::makeResponse(statusCode: 200),
        OperationNodeFactory::makeResponse(statusCode: 404),
    ]);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('response.duplicate-status')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('200')
        ->and($findings[0]->message)->toContain('2 times');
});

it('emits no findings when all response status codes are unique', function (): void {
    $rule = new ResponseDuplicateStatus();
    $operation = OperationNodeFactory::makeOperation(responses: [
        OperationNodeFactory::makeResponse(statusCode: 200),
        OperationNodeFactory::makeResponse(statusCode: 404),
        OperationNodeFactory::makeResponse(statusCode: 500),
    ]);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when an operation has no responses', function (): void {
    $rule = new ResponseDuplicateStatus();
    $operation = OperationNodeFactory::makeOperation(responses: []);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits multiple findings for multiple duplicated status codes', function (): void {
    $rule = new ResponseDuplicateStatus();
    $operation = OperationNodeFactory::makeOperation(responses: [
        OperationNodeFactory::makeResponse(statusCode: 200),
        OperationNodeFactory::makeResponse(statusCode: 200),
        OperationNodeFactory::makeResponse(statusCode: 404),
        OperationNodeFactory::makeResponse(statusCode: 404),
    ]);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(2);
});
