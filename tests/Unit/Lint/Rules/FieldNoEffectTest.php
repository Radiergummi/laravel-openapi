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
use Radiergummi\OpenApi\Core\Lint\Rules\FieldNoEffect;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithNoEffectData;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithNoEffectDataController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\NoEffectFixtureController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;
use Spatie\LaravelData\Data;

uses()->group('openapi', 'lint');

function makeDirectScannerForNoEffect(): PayloadParameterScanner
{
    return new PayloadParameterScanner(indirectionClasses: []);
}

it('reports its id and level', function (): void {
    $rule = new FieldNoEffect(makeDirectScannerForNoEffect());

    expect($rule->id())->toBe('field.no-effect')
        ->and($rule->level())->toBe(3);
});

it('emits a finding when RequestField has all default values', function (): void {
    $rule = new FieldNoEffect(makeDirectScannerForNoEffect());
    $descriptor = ActionDescriptorFactory::forControllerMethod(NoEffectFixtureController::class, 'withNoEffect', '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/api/v0/test');
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.no-effect')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('$noEffect')
        ->and($findings[0]->message)->toContain('no parameters set');
});

it('emits no findings when there is no descriptor on the operation', function (): void {
    $rule = new FieldNoEffect(makeDirectScannerForNoEffect());
    $operation = new OperationNode(
        pathUri: '/api/v0/test',
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
        webhook: false,
    );
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no findings when the method has no Data class parameters', function (): void {
    $rule = new FieldNoEffect(makeDirectScannerForNoEffect());
    $descriptor = ActionDescriptorFactory::forControllerMethod(NoEffectFixtureController::class, 'withoutData', '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/api/v0/test');
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('does not flag RequestField attributes that have at least one parameter set', function (): void {
    $rule = new FieldNoEffect(makeDirectScannerForNoEffect());
    $descriptor = ActionDescriptorFactory::forControllerMethod(NoEffectFixtureController::class, 'withNoEffect', '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/api/v0/test');
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    // Only $noEffect should fire; $hasDescription and $hasWriteOnly should not.
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('$noEffect');
});

it('provides a fix hint suggesting removal or adding a parameter', function (): void {
    $rule = new FieldNoEffect(makeDirectScannerForNoEffect());
    $descriptor = ActionDescriptorFactory::forControllerMethod(NoEffectFixtureController::class, 'withNoEffect', '/fixture');
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/api/v0/test');
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings[0]->fixHint)->toContain('Remove')
        ->and($findings[0]->fixHint)->toContain('description');
});

it('emits a finding for a Data class injected through a Domain Action', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(ActionWithNoEffectDataController::class, 'create', '/fixture', ['POST']);
    $operation = OperationNodeFactory::forDescriptor($descriptor, pathUri: '/api/v0/test');
    $context = OperationNodeFactory::emptyContext(payloadClasses: [Data::class]);

    // Scanner descends into ActionWithNoEffectData's constructor to find NoEffectFixtureData.
    $scanner = new PayloadParameterScanner(indirectionClasses: [ActionWithNoEffectData::class]);
    $findings = iterator_to_array(
        (new FieldNoEffect($scanner))->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.no-effect')
        ->and($findings[0]->message)->toContain('$noEffect');
});
