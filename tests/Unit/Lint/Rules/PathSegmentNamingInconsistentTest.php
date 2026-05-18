<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\PathSegmentNamingInconsistent;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;
use OpenApi\Annotations as OA;
use OpenApi\Context;

uses()->group('openapi', 'lint');

function makePathSegmentContext(): LintContext
{
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);

    return new LintContext(
        api: new ApiNode(operations: [], components: [], webhooks: [], declaredTags: [], tagDescriptions: [], raw: $spec),
        index: TreeIndex::empty(),
        rawSpec: $spec,
        actionDescriptors: [],
        suppressions: [],
    );
}

function makePathSegmentOperation(string $pathUri): OperationNode
{
    return new OperationNode(
        pathUri: $pathUri,
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
    );
}

it('reports its id and level', function (): void {
    $rule = new PathSegmentNamingInconsistent();

    expect($rule->id())->toBe('path.segment-naming-inconsistent')
        ->and($rule->level())->toBe(3);
});

it('default (kebab): passes a valid kebab-case path', function (): void {
    $rule = new PathSegmentNamingInconsistent();
    $context = makePathSegmentContext();

    $findings = iterator_to_array($rule->checkOperation(makePathSegmentOperation('/api/v0/import-jobs'), $context));

    expect($findings)->toBe([]);
});

it('default (kebab): passes a path with only a param placeholder', function (): void {
    $rule = new PathSegmentNamingInconsistent();
    $context = makePathSegmentContext();

    $findings = iterator_to_array($rule->checkOperation(makePathSegmentOperation('/api/v0/projects/{project}'), $context));

    expect($findings)->toBe([]);
});

it('default (kebab): passes a path with mixed static and placeholder segments', function (): void {
    $rule = new PathSegmentNamingInconsistent();
    $context = makePathSegmentContext();

    $findings = iterator_to_array($rule->checkOperation(makePathSegmentOperation('/api/v0/{project}/phase-runs'), $context));

    expect($findings)->toBe([]);
});

it('default (kebab): flags a snake_case segment', function (): void {
    $rule = new PathSegmentNamingInconsistent();
    $context = makePathSegmentContext();

    $findings = iterator_to_array($rule->checkOperation(makePathSegmentOperation('/api/v0/import_jobs'), $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('path.segment-naming-inconsistent')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('import_jobs')
        ->and($findings[0]->message)->toContain('kebab-case');
});

it('default (kebab): flags a camelCase segment', function (): void {
    $rule = new PathSegmentNamingInconsistent();
    $context = makePathSegmentContext();

    $findings = iterator_to_array($rule->checkOperation(makePathSegmentOperation('/api/v0/importJobs'), $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('importJobs');
});

it('emits one finding listing all offending segments', function (): void {
    $rule = new PathSegmentNamingInconsistent();
    $context = makePathSegmentContext();

    $findings = iterator_to_array($rule->checkOperation(makePathSegmentOperation('/api_v0/import_jobs'), $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('api_v0')
        ->and($findings[0]->message)->toContain('import_jobs');
});

it('skips placeholder segments entirely', function (): void {
    $rule = new PathSegmentNamingInconsistent();
    $context = makePathSegmentContext();

    // {projectId} would fail kebab, but must be skipped
    $findings = iterator_to_array($rule->checkOperation(makePathSegmentOperation('/api/v0/{projectId}/entries'), $context));

    expect($findings)->toBe([]);
});

it('snake case: passes a valid snake_case path', function (): void {
    $rule = new PathSegmentNamingInconsistent(IdentifierCase::Snake);
    $context = makePathSegmentContext();

    $findings = iterator_to_array($rule->checkOperation(makePathSegmentOperation('/api/v0/import_jobs'), $context));

    expect($findings)->toBe([]);
});

it('snake case: flags a kebab-case segment', function (): void {
    $rule = new PathSegmentNamingInconsistent(IdentifierCase::Snake);
    $context = makePathSegmentContext();

    $findings = iterator_to_array($rule->checkOperation(makePathSegmentOperation('/api/v0/import-jobs'), $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('snake_case');
});
