<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Lint\Rules\FieldEnumMismatch;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithEnumMismatchData;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithEnumMismatchDataController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\EnumMismatchFixtureController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;
use Spatie\LaravelData\Data;

uses()->group('openapi', 'lint');

function makeDirectScannerForEnumMismatch(): PayloadParameterScanner
{
    return new PayloadParameterScanner(indirectionClasses: []);
}

it('reports its id and level', function (): void {
    $rule = new FieldEnumMismatch(makeDirectScannerForEnumMismatch());

    expect($rule->id())
        ->toBe('field.enum-mismatch')
        ->and($rule->level())->toBe(0);
});

it('emits a finding when RequestField enum values do not match BackedEnum cases', function (): void {
    $rule = new FieldEnumMismatch(makeDirectScannerForEnumMismatch());
    $descriptor = ActionDescriptorFactory::forControllerMethod(
        EnumMismatchFixtureController::class,
        'withMismatch',
        '/fixture',
        ['PUT'],
    );
    $operation = OperationNodeFactory::forDescriptor(
        $descriptor,
        method: HttpMethod::Put,
        pathUri: '/api/v0/test',
    );
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.enum-mismatch')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('$mismatched')
        ->and($findings[0]->message)->toContain('missing')
        ->and($findings[0]->message)->toContain('pending');
});

it('emits no findings when there is no descriptor on the operation', function (): void {
    $rule = new FieldEnumMismatch(makeDirectScannerForEnumMismatch());
    $operation = OperationNodeFactory::makeOperation(pathUri: '/api/v0/test', method: HttpMethod::Put);
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no findings when the method has no Data class parameters', function (): void {
    $rule = new FieldEnumMismatch(makeDirectScannerForEnumMismatch());
    $descriptor = ActionDescriptorFactory::forControllerMethod(
        EnumMismatchFixtureController::class,
        'withoutData',
        '/fixture',
        ['PUT'],
    );
    $operation = OperationNodeFactory::forDescriptor(
        $descriptor,
        method: HttpMethod::Put,
        pathUri: '/api/v0/test',
    );
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('does not flag matching enums, properties without enum, or non-BackedEnum types', function (): void {
    $rule = new FieldEnumMismatch(makeDirectScannerForEnumMismatch());
    $descriptor = ActionDescriptorFactory::forControllerMethod(
        EnumMismatchFixtureController::class,
        'withMismatch',
        '/fixture',
        ['PUT'],
    );
    $operation = OperationNodeFactory::forDescriptor(
        $descriptor,
        method: HttpMethod::Put,
        pathUri: '/api/v0/test',
    );
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    // Only $mismatched should fire; $matching (exact cases), $noEnum (null),
    // and $nonEnumType (string, not BackedEnum) should not.
    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->message)->toContain('$mismatched');
});

it('reports missing enum case values in the finding message', function (): void {
    $rule = new FieldEnumMismatch(makeDirectScannerForEnumMismatch());
    $descriptor = ActionDescriptorFactory::forControllerMethod(
        EnumMismatchFixtureController::class,
        'withMismatch',
        '/fixture',
        ['PUT'],
    );
    $operation = OperationNodeFactory::forDescriptor(
        $descriptor,
        method: HttpMethod::Put,
        pathUri: '/api/v0/test',
    );
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    // The fixture enum has [active, inactive, pending] but RequestField only lists [active, inactive]
    expect($findings[0]->message)->toContain('missing [pending]');
});

it('emits a finding for a Data class injected through a Domain Action', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(
        ActionWithEnumMismatchDataController::class,
        'create',
        '/fixture',
        ['POST'],
    );
    $operation = OperationNodeFactory::forDescriptor(
        $descriptor,
        method: HttpMethod::Put,
        pathUri: '/api/v0/test',
    );
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    // Scanner descends into ActionWithEnumMismatchData's constructor to find EnumMismatchFixtureData.
    $scanner = new PayloadParameterScanner(indirectionClasses: [ActionWithEnumMismatchData::class]);
    $findings = iterator_to_array(
        new FieldEnumMismatch($scanner)->checkOperation($operation, $context),
    );

    expect($findings)
        ->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.enum-mismatch')
        ->and($findings[0]->message)->toContain('$mismatched');
});
