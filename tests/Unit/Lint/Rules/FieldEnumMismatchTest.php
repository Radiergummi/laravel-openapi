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
use Radiergummi\OpenApi\Core\Lint\Rules\FieldEnumMismatch;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithEnumMismatchData;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\ActionWithEnumMismatchDataController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\EnumMismatchFixtureController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;
use Spatie\LaravelData\Data;

uses()->group('openapi', 'lint');

function makeDirectScannerForEnumMismatch(): PayloadParameterScanner
{
    return new PayloadParameterScanner(indirectionClasses: []);
}

function makeEnumMismatchOperation(?ActionDescriptor $descriptor): OperationNode
{
    return new OperationNode(
        pathUri: '/api/v0/test',
        method: 'PUT',
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
        raw: new OA\Put(['_context' => new Context()]),
        webhook: false,
    );
}

function makeEnumMismatchContext(): LintContext
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
    $rule = new FieldEnumMismatch(makeDirectScannerForEnumMismatch());

    expect($rule->id())->toBe('field.enum-mismatch')
        ->and($rule->level())->toBe(0);
});

it('emits a finding when RequestField enum values do not match BackedEnum cases', function (): void {
    $rule = new FieldEnumMismatch(makeDirectScannerForEnumMismatch());
    $descriptor = ActionDescriptorFactory::forControllerMethod(EnumMismatchFixtureController::class, 'withMismatch', '/fixture', ['PUT']);
    $operation = makeEnumMismatchOperation($descriptor);
    $context = makeEnumMismatchContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.enum-mismatch')
        ->and($findings[0]->level)->toBe(0)
        ->and($findings[0]->message)->toContain('$mismatched')
        ->and($findings[0]->message)->toContain('missing')
        ->and($findings[0]->message)->toContain('pending');
});

it('emits no findings when there is no descriptor on the operation', function (): void {
    $rule = new FieldEnumMismatch(makeDirectScannerForEnumMismatch());
    $operation = makeEnumMismatchOperation(null);
    $context = makeEnumMismatchContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('emits no findings when the method has no Data class parameters', function (): void {
    $rule = new FieldEnumMismatch(makeDirectScannerForEnumMismatch());
    $descriptor = ActionDescriptorFactory::forControllerMethod(EnumMismatchFixtureController::class, 'withoutData', '/fixture', ['PUT']);
    $operation = makeEnumMismatchOperation($descriptor);
    $context = makeEnumMismatchContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    expect($findings)->toBe([]);
});

it('does not flag matching enums, properties without enum, or non-BackedEnum types', function (): void {
    $rule = new FieldEnumMismatch(makeDirectScannerForEnumMismatch());
    $descriptor = ActionDescriptorFactory::forControllerMethod(EnumMismatchFixtureController::class, 'withMismatch', '/fixture', ['PUT']);
    $operation = makeEnumMismatchOperation($descriptor);
    $context = makeEnumMismatchContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    // Only $mismatched should fire; $matching (exact cases), $noEnum (null),
    // and $nonEnumType (string, not BackedEnum) should not.
    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('$mismatched');
});

it('reports missing enum case values in the finding message', function (): void {
    $rule = new FieldEnumMismatch(makeDirectScannerForEnumMismatch());
    $descriptor = ActionDescriptorFactory::forControllerMethod(EnumMismatchFixtureController::class, 'withMismatch', '/fixture', ['PUT']);
    $operation = makeEnumMismatchOperation($descriptor);
    $context = makeEnumMismatchContext();

    $findings = iterator_to_array($rule->checkOperation($operation, $context));

    // The fixture enum has [active, inactive, pending] but RequestField only lists [active, inactive]
    expect($findings[0]->message)->toContain('missing [pending]');
});

it('emits a finding for a Data class injected through a Domain Action', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(ActionWithEnumMismatchDataController::class, 'create', '/fixture', ['POST']);
    $operation = makeEnumMismatchOperation($descriptor);
    $context = makeEnumMismatchContext();

    // Scanner descends into ActionWithEnumMismatchData's constructor to find EnumMismatchFixtureData.
    $scanner = new PayloadParameterScanner(indirectionClasses: [ActionWithEnumMismatchData::class]);
    $findings = iterator_to_array(
        (new FieldEnumMismatch($scanner))->checkOperation($operation, $context),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('field.enum-mismatch')
        ->and($findings[0]->message)->toContain('$mismatched');
});
