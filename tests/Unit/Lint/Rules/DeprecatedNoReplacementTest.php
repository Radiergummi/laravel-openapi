<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Lint\Rules\DeprecatedNoReplacement;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new DeprecatedNoReplacement();

    expect($rule->id())->toBe('deprecated.no-replacement')
        ->and($rule->level())->toBe(4);
});

it('emits a finding for deprecated operations missing replacement guidance', function (?string $description): void {
    $operation = OperationNodeFactory::makeOperation(
        description: $description,
        deprecated: true,
        responses: [],
    );

    $findings = iterator_to_array(
        new DeprecatedNoReplacement()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('deprecated.no-replacement')
        ->and($findings[0]->level)->toBe(4)
        ->and($findings[0]->message)->toContain('replacement');
})->with([
    'no description'                       => [null],
    'description does not mention anything' => ['This endpoint is old and should not be relied upon.'],
]);

it('emits no findings when description mentions a replacement keyword', function (string $description): void {
    $operation = OperationNodeFactory::makeOperation(
        description: $description,
        deprecated: true,
        responses: [],
    );

    $findings = iterator_to_array(
        new DeprecatedNoReplacement()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    'use'                  => ['Deprecated. Use GET /v2/resource instead.'],
    'replaced by'          => ['This endpoint has been replaced by the new v2 endpoint.'],
    'replacement'          => ['A replacement endpoint is available at /v2/resource.'],
    'sunset'               => ['This endpoint will sunset on 2025-12-31.'],
    'case-insensitive'     => ['REPLACED BY the new endpoint.'],
]);

it('emits no findings for non-deprecated operations', function (): void {
    $operation = OperationNodeFactory::makeOperation(deprecated: false, responses: []);

    $findings = iterator_to_array(
        new DeprecatedNoReplacement()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

// region Bug 8: x-replacement OAS extension satisfies the requirement

it('emits no findings when deprecated operation has a non-empty x-replacement extension (Bug 8)', function (): void {
    $raw = new OA\Get(['_context' => new Context()]);
    $raw->x = ['x-replacement' => 'GET /v2/resource'];

    $operation = OperationNodeFactory::makeOperation(deprecated: true, responses: [], raw: $raw);

    $findings = iterator_to_array(
        new DeprecatedNoReplacement()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('still emits a finding when x-replacement extension is an empty string (Bug 8)', function (): void {
    $raw = new OA\Get(['_context' => new Context()]);
    $raw->x = ['x-replacement' => ''];

    $operation = OperationNodeFactory::makeOperation(deprecated: true, responses: [], raw: $raw);

    $findings = iterator_to_array(
        new DeprecatedNoReplacement()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1);
});

// endregion
