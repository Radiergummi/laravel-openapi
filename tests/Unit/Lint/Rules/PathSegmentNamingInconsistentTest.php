<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\IdentifierCase;
use Radiergummi\OpenApi\Lint\Rules\PathSegmentNamingInconsistent;
use Radiergummi\OpenApi\Tests\Support\OperationNodeFactory;

uses()->group('openapi', 'lint');

it('reports its id and level', function (): void {
    $rule = new PathSegmentNamingInconsistent();

    expect($rule->id())->toBe('path.segment-naming-inconsistent')
        ->and($rule->level())->toBe(3);
});

it('default (kebab): passes paths whose static segments are kebab-case', function (string $pathUri): void {
    $rule = new PathSegmentNamingInconsistent();
    $operation = OperationNodeFactory::makeOperation(pathUri: $pathUri);

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
})->with([
    'kebab-case segments'                  => ['/api/v0/import-jobs'],
    'only placeholder segment'             => ['/api/v0/projects/{project}'],
    'mixed static and placeholder'         => ['/api/v0/{project}/phase-runs'],
    'placeholder skipped even if camelCase' => ['/api/v0/{projectId}/entries'],
]);

it('default (kebab): flags non-kebab static segments', function (string $pathUri, string $offending): void {
    $rule = new PathSegmentNamingInconsistent();
    $operation = OperationNodeFactory::makeOperation(pathUri: $pathUri);

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('path.segment-naming-inconsistent')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain($offending)
        ->and($findings[0]->message)->toContain('kebab-case');
})->with([
    'snake_case' => ['/api/v0/import_jobs', 'import_jobs'],
    'camelCase'  => ['/api/v0/importJobs', 'importJobs'],
]);

it('emits one finding listing all offending segments', function (): void {
    $rule = new PathSegmentNamingInconsistent();
    $operation = OperationNodeFactory::makeOperation(pathUri: '/api_v0/import_jobs');

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('api_v0')
        ->and($findings[0]->message)->toContain('import_jobs');
});

it('snake case: passes a valid snake_case path', function (): void {
    $rule = new PathSegmentNamingInconsistent(IdentifierCase::Snake);
    $operation = OperationNodeFactory::makeOperation(pathUri: '/api/v0/import_jobs');

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
});

it('snake case: flags a kebab-case segment', function (): void {
    $rule = new PathSegmentNamingInconsistent(IdentifierCase::Snake);
    $operation = OperationNodeFactory::makeOperation(pathUri: '/api/v0/import-jobs');

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('snake_case');
});
