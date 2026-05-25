<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\Rules\HeaderInvalidName;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\InvalidHeaderNameController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

function makeOperationNodeForHeaderInvalidName(string $methodName): OperationNode
{
    $descriptor = ActionDescriptorFactory::forControllerMethod(InvalidHeaderNameController::class, $methodName, '/fixture');

    return OperationNodeFactory::forDescriptor($descriptor, pathUri: '/fixture');
}

it('has the correct rule id and level', function (): void {
    $rule = new HeaderInvalidName();

    expect($rule->id())->toBe('header.invalid-name')->and($rule->level())->toBe(1);
});

it('emits no findings for valid header names', function (): void {
    $rule = new HeaderInvalidName();
    $operation = makeOperationNodeForHeaderInvalidName('withValidHeaders');
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits a finding for a header name with spaces', function (): void {
    $rule = new HeaderInvalidName();
    $operation = makeOperationNodeForHeaderInvalidName('withInvalidHeaderSpace');
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)
        ->toBe('header.invalid-name')
        ->and($findings[0]->level)
        ->toBe(1)
        ->and($findings[0]->message)
        ->toContain('Invalid Header Name');
});

it('emits a finding for an empty header name', function (): void {
    $rule = new HeaderInvalidName();
    $operation = makeOperationNodeForHeaderInvalidName('withEmptyHeaderName');
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)->and($findings[0]->ruleId)->toBe('header.invalid-name');
});

it('emits findings only for invalid headers in a mixed set', function (): void {
    $rule = new HeaderInvalidName();
    $operation = makeOperationNodeForHeaderInvalidName('withMixedHeaders');
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)->and($findings[0]->message)->toContain('also/invalid');
});

it('emits no findings when a method has no header attributes', function (): void {
    $rule = new HeaderInvalidName();
    $operation = makeOperationNodeForHeaderInvalidName('withoutHeaders');
    $context = OperationNodeFactory::emptyContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no findings when operation has no descriptor', function (): void {
    $operation = OperationNodeFactory::makeOperation(responses: [], descriptor: null);

    $findings = iterator_to_array(
        new HeaderInvalidName()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
