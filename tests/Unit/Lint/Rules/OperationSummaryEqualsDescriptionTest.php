<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Rules\OperationSummaryEqualsDescription;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new OperationSummaryEqualsDescription();

    expect($rule->id)->toBe('operation.summary-equals-description')
        ->and($rule->severity)->toBe(Severity::Inconsistent);
});

it('emits a finding when summary and description match (case-insensitive, trimmed)', function (string $summary, string $description): void {
    $rule = new OperationSummaryEqualsDescription();
    $operation = OperationNodeFactory::makeOperation(summary: $summary, description: $description);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.summary-equals-description')
        ->and($findings[0]->severity)->toBe(Severity::Inconsistent);
})->with([
    'identical'                       => ['List projects', 'List projects'],
    'differing case and surrounding whitespace' => ['List projects', '  list projects  '],
]);

it('emits no findings when description adds more detail than the summary', function (): void {
    $rule = new OperationSummaryEqualsDescription();
    $operation = OperationNodeFactory::makeOperation(
        summary: 'List projects',
        description: 'Returns a paginated list of all projects.',
    );

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when one side is null', function (?string $summary, ?string $description): void {
    $rule = new OperationSummaryEqualsDescription();
    $operation = OperationNodeFactory::makeOperation(summary: $summary, description: $description);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
})->with([
    'summary set, description null' => ['List projects', null],
    'summary null, description set' => [null, 'Returns a paginated list of all projects.'],
]);
