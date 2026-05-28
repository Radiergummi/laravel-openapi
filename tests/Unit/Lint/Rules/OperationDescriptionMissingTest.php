<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\OperationDescriptionMissing;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('has the correct rule id and level', function (): void {
    $rule = new OperationDescriptionMissing();

    expect($rule->id())->toBe('operation.description-missing')
        ->and($rule->level())->toBe(2);
});

it('emits a finding when operation has a summary but no description', function (): void {
    $rule = new OperationDescriptionMissing();
    $operation = OperationNodeFactory::makeOperation(
        summary: 'List all users',
        description: null,
    );

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('operation.description-missing')
        ->and($findings[0]->level)->toBe(2)
        ->and($findings[0]->message)->toContain('GET')
        ->and($findings[0]->message)->toContain('/test');
});

it('emits no findings when operation has both summary and description', function (): void {
    $rule = new OperationDescriptionMissing();
    $operation = OperationNodeFactory::makeOperation(
        summary: 'List all users',
        description: 'Returns a paginated list of all users in the system.',
    );

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when operation has no summary (handled by summary.missing)', function (): void {
    $rule = new OperationDescriptionMissing();
    $operation = OperationNodeFactory::makeOperation(summary: null, description: null);

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});

it('emits no findings when operation has description but no summary', function (): void {
    $rule = new OperationDescriptionMissing();
    $operation = OperationNodeFactory::makeOperation(
        summary: null,
        description: 'A detailed description.',
    );

    $findings = iterator_to_array(
        $rule->checkOperation($operation, OperationNodeFactory::emptyContext()),
    );

    expect($findings)->toBe([]);
});
