<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\Rules\FieldConflictingType;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithConflictingTypeData;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithConflictingTypeDataController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ConflictingTypeFixtureController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;
use Spatie\LaravelData\Data;

uses()->group('openapi', 'lint');

function makeDirectScannerForConflictingType(): PayloadParameterScanner
{
    return new PayloadParameterScanner(indirectionClasses: []);
}

it('reports its id and level', function (): void {
    $rule = new FieldConflictingType(makeDirectScannerForConflictingType());

    expect($rule->id())
        ->toBe('field.conflicting-type')
        ->and($rule->level())->toBe(1);
});

it('emits a finding when RequestField type contradicts the PHP type', function (): void {
    $rule = new FieldConflictingType(makeDirectScannerForConflictingType());
    $descriptor = ActionDescriptorFactory::forControllerMethod(
        ConflictingTypeFixtureController::class,
        'withConflict',
        '/fixture',
    );
    $operation = OperationNodeFactory::forDescriptor(
        $descriptor,
        method: HttpMethod::Post,
        pathUri: '/api/v0/test',
    );
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.conflicting-type')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('$conflicting')
        ->and($findings[0]->message)->toContain('"integer"')
        ->and($findings[0]->message)->toContain('"string"');
});

it('emits no findings when there is no descriptor on the operation', function (): void {
    $rule = new FieldConflictingType(makeDirectScannerForConflictingType());
    $operation = OperationNodeFactory::makeOperation(pathUri: '/api/v0/test', method: HttpMethod::Post);
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no findings when the method has no Data class parameters', function (): void {
    $rule = new FieldConflictingType(makeDirectScannerForConflictingType());
    $descriptor = ActionDescriptorFactory::forControllerMethod(
        ConflictingTypeFixtureController::class,
        'withoutData',
        '/fixture',
    );
    $operation = OperationNodeFactory::forDescriptor(
        $descriptor,
        method: HttpMethod::Post,
        pathUri: '/api/v0/test',
    );
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('does not flag matching types or properties without explicit type', function (): void {
    $rule = new FieldConflictingType(makeDirectScannerForConflictingType());
    $descriptor = ActionDescriptorFactory::forControllerMethod(
        ConflictingTypeFixtureController::class,
        'withConflict',
        '/fixture',
    );
    $operation = OperationNodeFactory::forDescriptor(
        $descriptor,
        method: HttpMethod::Post,
        pathUri: '/api/v0/test',
    );
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    // Only the $conflicting property should fire; $matching (string→string)
    // and $noExplicitType (no type param) should not.
    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->message)->toContain('$conflicting');
});

it('provides a fix hint with the expected type', function (): void {
    $rule = new FieldConflictingType(makeDirectScannerForConflictingType());
    $descriptor = ActionDescriptorFactory::forControllerMethod(
        ConflictingTypeFixtureController::class,
        'withConflict',
        '/fixture',
    );
    $operation = OperationNodeFactory::forDescriptor(
        $descriptor,
        method: HttpMethod::Post,
        pathUri: '/api/v0/test',
    );
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings[0]->fixHint)->toContain('"integer"');
});

it('emits a finding for a Data class injected through a Domain Action', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(
        ActionWithConflictingTypeDataController::class,
        'create',
        '/fixture',
        ['POST'],
    );
    $operation = OperationNodeFactory::forDescriptor(
        $descriptor,
        method: HttpMethod::Post,
        pathUri: '/api/v0/test',
    );
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    // Scanner descends into ActionWithConflictingTypeData's constructor to find ConflictingTypeFixtureData.
    $scanner = new PayloadParameterScanner(indirectionClasses: [ActionWithConflictingTypeData::class]);
    $findings = iterator_to_array(
        new FieldConflictingType($scanner)->checkOperation($operation, $context),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.conflicting-type')
        ->and($findings[0]->message)->toContain('$conflicting');
});
