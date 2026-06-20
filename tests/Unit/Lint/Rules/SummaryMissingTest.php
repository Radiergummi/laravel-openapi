<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\SummaryMissing;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new SummaryMissing();

    expect($rule->id)->toBe('summary.missing')
        ->and($rule->severity)->toBe(Severity::Underspecified);
});

it('emits a finding when summary is missing', function (): void {
    $rule = new SummaryMissing();
    $operation = OperationNodeFactory::makeOperation(summary: null);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('summary.missing')
        ->and($findings[0]->severity)->toBe(Severity::Underspecified)
        ->and($findings[0]->message)->toContain('no summary');
});

it('emits no findings when summary is present', function (): void {
    $rule = new SummaryMissing();
    $operation = OperationNodeFactory::makeOperation(summary: 'Retrieves a list of resources.');

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
