<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Extractors\PayloadParameterScanner;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\FieldConflictingType;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use Spatie\LaravelData\Data;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithConflictingTypeData;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithConflictingTypeDataController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ConflictingTypeFixtureController;

uses()->group('openapi', 'lint');

function makeDirectScannerForConflictingType(): PayloadParameterScanner
{
    return new PayloadParameterScanner(indirectionClasses: []);
}

function makeConflictingTypeDescriptor(string $method): ActionDescriptor
{
    $reflection = new ReflectionMethod(ConflictingTypeFixtureController::class, $method);
    $route = new Route(['GET'], '/fixture', [ConflictingTypeFixtureController::class, $method]);

    return new ActionDescriptor(
        route: $route,
        controller: $reflection->getDeclaringClass(),
        method: $reflection,
        summary: null,
        description: null,
    );
}

function makeConflictingTypeOperation(?ActionDescriptor $descriptor): OperationNode
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

function makeConflictingTypeContext(): LintContext
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
    $rule = new FieldConflictingType(makeDirectScannerForConflictingType());

    expect($rule->id())->toBe('field.conflicting-type')
        ->and($rule->level())->toBe(1);
});

it('emits a finding when RequestField type contradicts the PHP type', function (): void {
    $rule = new FieldConflictingType(makeDirectScannerForConflictingType());
    $descriptor = makeConflictingTypeDescriptor('withConflict');
    $operation = makeConflictingTypeOperation($descriptor);
    $context = makeConflictingTypeContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.conflicting-type')
        ->and($findings[0]->level)->toBe(1)
        ->and($findings[0]->message)->toContain('$conflicting')
        ->and($findings[0]->message)->toContain('"integer"')
        ->and($findings[0]->message)->toContain('"string"');
});

it('emits no findings when there is no descriptor on the operation', function (): void {
    $rule = new FieldConflictingType(makeDirectScannerForConflictingType());
    $operation = makeConflictingTypeOperation(null);
    $context = makeConflictingTypeContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no findings when the method has no Data class parameters', function (): void {
    $rule = new FieldConflictingType(makeDirectScannerForConflictingType());
    $descriptor = makeConflictingTypeDescriptor('withoutData');
    $operation = makeConflictingTypeOperation($descriptor);
    $context = makeConflictingTypeContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('does not flag matching types or properties without explicit type', function (): void {
    $rule = new FieldConflictingType(makeDirectScannerForConflictingType());
    $descriptor = makeConflictingTypeDescriptor('withConflict');
    $operation = makeConflictingTypeOperation($descriptor);
    $context = makeConflictingTypeContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    // Only the $conflicting property should fire; $matching (string→string)
    // and $noExplicitType (no type param) should not.
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('$conflicting');
});

it('provides a fix hint with the expected type', function (): void {
    $rule = new FieldConflictingType(makeDirectScannerForConflictingType());
    $descriptor = makeConflictingTypeDescriptor('withConflict');
    $operation = makeConflictingTypeOperation($descriptor);
    $context = makeConflictingTypeContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings[0]->fixHint)->toContain('"integer"');
});

it('emits a finding for a Data class injected through a Domain Action', function (): void {
    $reflection = new ReflectionMethod(ActionWithConflictingTypeDataController::class, 'create');
    $route = new Route(['POST'], '/fixture', [ActionWithConflictingTypeDataController::class, 'create']);
    $descriptor = new ActionDescriptor(
        route: $route,
        controller: $reflection->getDeclaringClass(),
        method: $reflection,
        summary: null,
        description: null,
    );
    $operation = makeConflictingTypeOperation($descriptor);
    $context = makeConflictingTypeContext();

    // Scanner descends into ActionWithConflictingTypeData's constructor to find ConflictingTypeFixtureData.
    $scanner = new PayloadParameterScanner(indirectionClasses: [ActionWithConflictingTypeData::class]);
    $findings = iterator_to_array(
        (new FieldConflictingType($scanner))->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.conflicting-type')
        ->and($findings[0]->message)->toContain('$conflicting');
});
