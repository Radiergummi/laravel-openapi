<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\DeprecatedNoSunsetDate;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new DeprecatedNoSunsetDate();

    expect($rule->id)->toBe('deprecated.no-sunset-date')
        ->and($rule->severity)->toBe(Severity::Improvable);
});

it('emits a finding when a deprecated operation has no concrete sunset date', function (?string $description): void {
    $operation = OperationNodeFactory::makeOperation(
        description: $description,
        deprecated: true,
        responses: [],
    );

    $findings = iterator_to_array(
        new DeprecatedNoSunsetDate()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('deprecated.no-sunset-date')
        ->and($findings[0]->severity)->toBe(Severity::Improvable)
        ->and($findings[0]->message)->toContain('sunset');
})->with([
    'no description'         => [null],
    'description has no date' => ['This endpoint is deprecated. Please migrate to the new API.'],
    'month name only'        => ['Deprecated. Will be removed in January 2026.'],
    'modal verb "may"'       => ['This endpoint is deprecated and may be removed in the future.'],
]);

it('emits no findings when description contains a concrete date', function (string $description): void {
    $operation = OperationNodeFactory::makeOperation(
        description: $description,
        deprecated: true,
        responses: [],
    );

    $findings = iterator_to_array(
        new DeprecatedNoSunsetDate()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    'ISO date'         => ['Deprecated. Will be removed on 2025-12-31.'],
    'quarter notation' => ['Deprecated. Sunset in Q1 2026.'],
]);

it('emits no findings for non-deprecated operations', function (): void {
    $operation = OperationNodeFactory::makeOperation(deprecated: false, responses: []);

    $findings = iterator_to_array(
        new DeprecatedNoSunsetDate()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

// region Bug 8: x-sunset OAS extension satisfies the requirement

it('emits no findings when deprecated operation has a non-empty x-sunset extension (Bug 8)', function (): void {
    $raw = new OA\Get(['_context' => new Context()]);
    $raw->x = ['x-sunset' => '2026-12-31'];

    $operation = OperationNodeFactory::makeOperation(deprecated: true, responses: [], raw: $raw);

    $findings = iterator_to_array(
        new DeprecatedNoSunsetDate()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('still emits a finding when x-sunset extension is an empty string (Bug 8)', function (): void {
    $raw = new OA\Get(['_context' => new Context()]);
    $raw->x = ['x-sunset' => ''];

    $operation = OperationNodeFactory::makeOperation(deprecated: true, responses: [], raw: $raw);

    $findings = iterator_to_array(
        new DeprecatedNoSunsetDate()->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1);
});

// endregion
