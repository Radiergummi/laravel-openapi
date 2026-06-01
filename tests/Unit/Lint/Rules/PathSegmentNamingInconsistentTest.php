<?php

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

it('default (kebab): accepts path segments with short lowercase file-extension tails', function (string $pathUri): void {
    $rule = new PathSegmentNamingInconsistent();
    $operation = OperationNodeFactory::makeOperation(pathUri: $pathUri);

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toBe([]);
})->with([
    'yaml spec'    => '/api/openapi.yaml',
    'atom feed'    => '/feed.atom',
    'sitemap'      => '/sitemap.xml',
    'pdf download' => '/reports/quarterly.pdf',
]);

it('default (kebab): still flags a dotted segment whose head violates the case', function (): void {
    $rule = new PathSegmentNamingInconsistent();
    $operation = OperationNodeFactory::makeOperation(pathUri: '/api/Some.yaml');

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('Some.yaml');
});

it('default (kebab): still flags an extension-like tail longer than 8 chars', function (): void {
    $rule = new PathSegmentNamingInconsistent();
    $operation = OperationNodeFactory::makeOperation(pathUri: '/api/name.somelongextension');

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1);
});

it('default (kebab): still flags an uppercase extension tail', function (): void {
    $rule = new PathSegmentNamingInconsistent();
    $operation = OperationNodeFactory::makeOperation(pathUri: '/api/file.YAML');

    $findings = iterator_to_array($rule->checkOperation($operation, OperationNodeFactory::emptyContext()));

    expect($findings)->toHaveCount(1);
});
