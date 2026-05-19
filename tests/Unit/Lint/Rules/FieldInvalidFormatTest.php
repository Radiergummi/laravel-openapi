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
use Radiergummi\OpenApi\Core\Extractors\PayloadParameterScanner;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\FieldInvalidFormat;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithInvalidFormatData;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithInvalidFormatDataController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\InvalidFormatFixtureController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Spatie\LaravelData\Data;

uses()->group('openapi', 'lint');

function makeDirectScannerForInvalidFormat(): PayloadParameterScanner
{
    return new PayloadParameterScanner(indirectionClasses: []);
}

function makeInvalidFormatOperation(?ActionDescriptor $descriptor): OperationNode
{
    return new OperationNode(
        pathUri: '/api/v0/test',
        method: 'POST',
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
        raw: new OA\Post(['_context' => new Context()]),
        webhook: false,
    );
}

function makeInvalidFormatContext(): LintContext
{
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
        payloadClasses: [Data::class],
    );
}

it('reports its id and level', function (): void {
    $rule = new FieldInvalidFormat(makeDirectScannerForInvalidFormat());

    expect($rule->id())->toBe('field.invalid-format')
        ->and($rule->level())->toBe(3);
});

it('emits a finding when RequestField declares an unrecognized format', function (): void {
    $rule = new FieldInvalidFormat(makeDirectScannerForInvalidFormat());
    $descriptor = ActionDescriptorFactory::forControllerMethod(InvalidFormatFixtureController::class, 'withInvalidFormat', '/fixture', ['POST']);
    $operation = makeInvalidFormatOperation($descriptor);
    $context = makeInvalidFormatContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.invalid-format')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('$invalidFormat')
        ->and($findings[0]->message)->toContain('"not-a-format"');
});

it('emits no findings when there is no descriptor on the operation', function (): void {
    $rule = new FieldInvalidFormat(makeDirectScannerForInvalidFormat());
    $operation = makeInvalidFormatOperation(null);
    $context = makeInvalidFormatContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no findings when the method has no Data class parameters', function (): void {
    $rule = new FieldInvalidFormat(makeDirectScannerForInvalidFormat());
    $descriptor = ActionDescriptorFactory::forControllerMethod(InvalidFormatFixtureController::class, 'withoutData', '/fixture', ['POST']);
    $operation = makeInvalidFormatOperation($descriptor);
    $context = makeInvalidFormatContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('does not flag valid formats or properties without a format', function (): void {
    $rule = new FieldInvalidFormat(makeDirectScannerForInvalidFormat());
    $descriptor = ActionDescriptorFactory::forControllerMethod(InvalidFormatFixtureController::class, 'withInvalidFormat', '/fixture', ['POST']);
    $operation = makeInvalidFormatOperation($descriptor);
    $context = makeInvalidFormatContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    // Only $invalidFormat should fire; $validFormat (date-time) and $noFormat (null) should not.
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('$invalidFormat');
});

it('provides a fix hint listing valid formats', function (): void {
    $rule = new FieldInvalidFormat(makeDirectScannerForInvalidFormat());
    $descriptor = ActionDescriptorFactory::forControllerMethod(InvalidFormatFixtureController::class, 'withInvalidFormat', '/fixture', ['POST']);
    $operation = makeInvalidFormatOperation($descriptor);
    $context = makeInvalidFormatContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings[0]->fixHint)->toContain('date-time')
        ->and($findings[0]->fixHint)->toContain('uuid');
});

it('emits a finding for a Data class injected through a Domain Action', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(ActionWithInvalidFormatDataController::class, 'create', '/fixture', ['POST']);
    $operation = makeInvalidFormatOperation($descriptor);
    $context = makeInvalidFormatContext();

    // Scanner descends into ActionWithInvalidFormatData's constructor to find InvalidFormatFixtureData.
    $scanner = new PayloadParameterScanner(indirectionClasses: [ActionWithInvalidFormatData::class]);
    $findings = iterator_to_array(
        (new FieldInvalidFormat($scanner))->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.invalid-format')
        ->and($findings[0]->message)->toContain('$invalidFormat');
});
