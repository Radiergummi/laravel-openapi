<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Core\Extractors\PayloadParameterScanner;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\FieldNoEffect;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithNoEffectData;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithNoEffectDataController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\NoEffectFixtureController;
use Spatie\LaravelData\Data;

uses()->group('openapi', 'lint');

function makeDirectScannerForNoEffect(): PayloadParameterScanner
{
    return new PayloadParameterScanner(indirectionClasses: []);
}

function makeNoEffectDescriptor(string $method): ActionDescriptor
{
    $reflection = new ReflectionMethod(NoEffectFixtureController::class, $method);
    $route = new Route(['GET'], '/fixture', [NoEffectFixtureController::class, $method]);

    return new ActionDescriptor(
        route: $route,
        controller: $reflection->getDeclaringClass(),
        method: $reflection,
        summary: null,
        description: null,
    );
}

function makeNoEffectOperation(?ActionDescriptor $descriptor): OperationNode
{
    return new OperationNode(
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
        descriptor: $descriptor,
        raw: new OA\Get(['_context' => new Context()]),
        webhook: false,
    );
}

function makeNoEffectContext(): LintContext
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
    $rule = new FieldNoEffect(makeDirectScannerForNoEffect());

    expect($rule->id())->toBe('field.no-effect')
        ->and($rule->level())->toBe(3);
});

it('emits a finding when RequestField has all default values', function (): void {
    $rule = new FieldNoEffect(makeDirectScannerForNoEffect());
    $descriptor = makeNoEffectDescriptor('withNoEffect');
    $operation = makeNoEffectOperation($descriptor);
    $context = makeNoEffectContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.no-effect')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('$noEffect')
        ->and($findings[0]->message)->toContain('no parameters set');
});

it('emits no findings when there is no descriptor on the operation', function (): void {
    $rule = new FieldNoEffect(makeDirectScannerForNoEffect());
    $operation = makeNoEffectOperation(null);
    $context = makeNoEffectContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no findings when the method has no Data class parameters', function (): void {
    $rule = new FieldNoEffect(makeDirectScannerForNoEffect());
    $descriptor = makeNoEffectDescriptor('withoutData');
    $operation = makeNoEffectOperation($descriptor);
    $context = makeNoEffectContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('does not flag RequestField attributes that have at least one parameter set', function (): void {
    $rule = new FieldNoEffect(makeDirectScannerForNoEffect());
    $descriptor = makeNoEffectDescriptor('withNoEffect');
    $operation = makeNoEffectOperation($descriptor);
    $context = makeNoEffectContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    // Only $noEffect should fire; $hasDescription and $hasWriteOnly should not.
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('$noEffect');
});

it('provides a fix hint suggesting removal or adding a parameter', function (): void {
    $rule = new FieldNoEffect(makeDirectScannerForNoEffect());
    $descriptor = makeNoEffectDescriptor('withNoEffect');
    $operation = makeNoEffectOperation($descriptor);
    $context = makeNoEffectContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings[0]->fixHint)->toContain('Remove')
        ->and($findings[0]->fixHint)->toContain('description');
});

it('emits a finding for a Data class injected through a Domain Action', function (): void {
    $reflection = new ReflectionMethod(ActionWithNoEffectDataController::class, 'create');
    $route = new Route(['POST'], '/fixture', [ActionWithNoEffectDataController::class, 'create']);
    $descriptor = new ActionDescriptor(
        route: $route,
        controller: $reflection->getDeclaringClass(),
        method: $reflection,
        summary: null,
        description: null,
    );
    $operation = makeNoEffectOperation($descriptor);
    $context = makeNoEffectContext();

    // Scanner descends into ActionWithNoEffectData's constructor to find NoEffectFixtureData.
    $scanner = new PayloadParameterScanner(indirectionClasses: [ActionWithNoEffectData::class]);
    $findings = iterator_to_array(
        (new FieldNoEffect($scanner))->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.no-effect')
        ->and($findings[0]->message)->toContain('$noEffect');
});
