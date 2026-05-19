<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\HeaderInvalidName;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\InvalidHeaderNameController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

uses()->group('openapi', 'lint');

function makeHeaderInvalidNameContext(): LintContext
{
    $spec = new OA\OpenApi(['_context' => new Context()]);

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}

function makeOperationNodeForHeaderInvalidName(string $methodName): OperationNode
{
    $descriptor = ActionDescriptorFactory::forControllerMethod(InvalidHeaderNameController::class, $methodName, '/fixture');

    return new OperationNode(
        pathUri: '/fixture',
        method: 'GET',
        operationId: null,
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: $descriptor,
        raw: new OA\Get(['_context' => new Context()]),
    );
}

it('has the correct rule id and level', function (): void {
    $rule = new HeaderInvalidName();

    expect($rule->id())->toBe('header.invalid-name')->and($rule->level())->toBe(1);
});

it('emits no findings for valid header names', function (): void {
    $rule = new HeaderInvalidName();
    $operation = makeOperationNodeForHeaderInvalidName('withValidHeaders');
    $context = makeHeaderInvalidNameContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits a finding for a header name with spaces', function (): void {
    $rule = new HeaderInvalidName();
    $operation = makeOperationNodeForHeaderInvalidName('withInvalidHeaderSpace');
    $context = makeHeaderInvalidNameContext();

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
    $context = makeHeaderInvalidNameContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)->and($findings[0]->ruleId)->toBe('header.invalid-name');
});

it('emits findings only for invalid headers in a mixed set', function (): void {
    $rule = new HeaderInvalidName();
    $operation = makeOperationNodeForHeaderInvalidName('withMixedHeaders');
    $context = makeHeaderInvalidNameContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)->and($findings[0]->message)->toContain('also/invalid');
});

it('emits no findings when a method has no header attributes', function (): void {
    $rule = new HeaderInvalidName();
    $operation = makeOperationNodeForHeaderInvalidName('withoutHeaders');
    $context = makeHeaderInvalidNameContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no findings when operation has no descriptor', function (): void {
    $rule = new HeaderInvalidName();
    $context = makeHeaderInvalidNameContext();

    $operation = new OperationNode(
        pathUri: '/test',
        method: 'GET',
        operationId: null,
        summary: null,
        description: null,
        deprecated: false,
        parameters: [],
        queryParameters: [],
        requestBody: null,
        responses: [],
        security: [],
        tags: [],
        descriptor: null,
        raw: new OA\Get(['_context' => new Context()]),
    );

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});
