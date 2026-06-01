<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\FieldInvalidFormat;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithInvalidFormatData;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithInvalidFormatDataController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\InvalidFormatFixtureController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;
use Spatie\LaravelData\Data;

uses()->group('openapi', 'lint');

function makeDirectScannerForInvalidFormat(): PayloadParameterScanner
{
    return new PayloadParameterScanner(indirectionClasses: []);
}

it('reports its id and level', function (): void {
    $rule = new FieldInvalidFormat(makeDirectScannerForInvalidFormat());

    expect($rule->id())->toBe('field.invalid-format')
        ->and($rule->level())->toBe(3);
});

it('emits a finding when RequestField declares an unrecognized format', function (): void {
    $rule = new FieldInvalidFormat(makeDirectScannerForInvalidFormat());
    $descriptor = ActionDescriptorFactory::forControllerMethod(InvalidFormatFixtureController::class, 'withInvalidFormat', '/fixture', ['POST']);
    $operation = OperationNodeFactory::forDescriptor($descriptor, method: 'POST', pathUri: '/api/v0/test');
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.invalid-format')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('$invalidFormat')
        ->and($findings[0]->message)->toContain('"not-a-format"');
});

it('emits no findings when there is no descriptor on the operation', function (): void {
    $rule = new FieldInvalidFormat(makeDirectScannerForInvalidFormat());
    $operation = OperationNodeFactory::makeOperation(pathUri: '/api/v0/test', method: 'POST');
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no findings when the method has no Data class parameters', function (): void {
    $rule = new FieldInvalidFormat(makeDirectScannerForInvalidFormat());
    $descriptor = ActionDescriptorFactory::forControllerMethod(InvalidFormatFixtureController::class, 'withoutData', '/fixture', ['POST']);
    $operation = OperationNodeFactory::forDescriptor($descriptor, method: 'POST', pathUri: '/api/v0/test');
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('does not flag valid formats or properties without a format', function (): void {
    $rule = new FieldInvalidFormat(makeDirectScannerForInvalidFormat());
    $descriptor = ActionDescriptorFactory::forControllerMethod(InvalidFormatFixtureController::class, 'withInvalidFormat', '/fixture', ['POST']);
    $operation = OperationNodeFactory::forDescriptor($descriptor, method: 'POST', pathUri: '/api/v0/test');
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    // Only $invalidFormat should fire; $validFormat (date-time) and $noFormat (null) should not.
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('$invalidFormat');
});

it('provides a fix hint listing valid formats', function (): void {
    $rule = new FieldInvalidFormat(makeDirectScannerForInvalidFormat());
    $descriptor = ActionDescriptorFactory::forControllerMethod(InvalidFormatFixtureController::class, 'withInvalidFormat', '/fixture', ['POST']);
    $operation = OperationNodeFactory::forDescriptor($descriptor, method: 'POST', pathUri: '/api/v0/test');
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings[0]->fixHint)->toContain('date-time')
        ->and($findings[0]->fixHint)->toContain('uuid');
});

it('emits a finding for a Data class injected through a Domain Action', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(ActionWithInvalidFormatDataController::class, 'create', '/fixture', ['POST']);
    $operation = OperationNodeFactory::forDescriptor($descriptor, method: 'POST', pathUri: '/api/v0/test');
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    // Scanner descends into ActionWithInvalidFormatData's constructor to find InvalidFormatFixtureData.
    $scanner = new PayloadParameterScanner(indirectionClasses: [ActionWithInvalidFormatData::class]);
    $findings = iterator_to_array(
        new FieldInvalidFormat($scanner)->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.invalid-format')
        ->and($findings[0]->message)->toContain('$invalidFormat');
});
