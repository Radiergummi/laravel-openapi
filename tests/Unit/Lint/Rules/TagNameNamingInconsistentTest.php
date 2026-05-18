<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\TagNameNamingInconsistent;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Core\Lint\TreeIndex;

uses()->group('openapi', 'lint');

function makeTagNamingApiNode(array $tagNames): ApiNode
{
    $spec = new OA\OpenApi(['openapi' => '3.1.0']);

    return new ApiNode(
        operations: [],
        components: [],
        webhooks: [],
        declaredTags: $tagNames,
        tagDescriptions: [],
        raw: $spec,
    );
}

function makeTagNamingContext(ApiNode $api): LintContext
{
    return new LintContext(
        api: $api,
        index: TreeIndex::empty(),
        rawSpec: $api->raw,
        actionDescriptors: [],
        suppressions: [],
    );
}

it('reports its id and level', function (): void {
    $rule = new TagNameNamingInconsistent();

    expect($rule->id())->toBe('tag.name-naming-inconsistent')
        ->and($rule->level())->toBe(3);
});

it('default (pascal): passes a valid PascalCase tag name', function (): void {
    $rule = new TagNameNamingInconsistent();
    $api = makeTagNamingApiNode(['Projects']);
    $context = makeTagNamingContext($api);

    $findings = iterator_to_array($rule->checkApi($api, $context));

    expect($findings)->toBe([]);
});

it('default (pascal): passes multiple valid PascalCase tag names', function (): void {
    $rule = new TagNameNamingInconsistent();
    $api = makeTagNamingApiNode(['Projects', 'ImportJobs', 'Users']);
    $context = makeTagNamingContext($api);

    $findings = iterator_to_array($rule->checkApi($api, $context));

    expect($findings)->toBe([]);
});

it('default (pascal): flags a kebab-case tag name', function (): void {
    $rule = new TagNameNamingInconsistent();
    $api = makeTagNamingApiNode(['import-jobs']);
    $context = makeTagNamingContext($api);

    $findings = iterator_to_array($rule->checkApi($api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('tag.name-naming-inconsistent')
        ->and($findings[0]->level)->toBe(3)
        ->and($findings[0]->message)->toContain('"import-jobs"')
        ->and($findings[0]->message)->toContain('PascalCase');
});

it('default (pascal): flags a snake_case tag name', function (): void {
    $rule = new TagNameNamingInconsistent();
    $api = makeTagNamingApiNode(['import_jobs']);
    $context = makeTagNamingContext($api);

    $findings = iterator_to_array($rule->checkApi($api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('"import_jobs"');
});

it('emits one finding per offending tag', function (): void {
    $rule = new TagNameNamingInconsistent();
    $api = makeTagNamingApiNode(['Projects', 'import-jobs', 'user_management']);
    $context = makeTagNamingContext($api);

    $findings = iterator_to_array($rule->checkApi($api, $context));

    expect($findings)->toHaveCount(2);
});

it('emits no findings when there are no tags', function (): void {
    $rule = new TagNameNamingInconsistent();
    $api = makeTagNamingApiNode([]);
    $context = makeTagNamingContext($api);

    $findings = iterator_to_array($rule->checkApi($api, $context));

    expect($findings)->toBe([]);
});

it('includes the json pointer using the array index in the finding location', function (): void {
    $rule = new TagNameNamingInconsistent();
    $api = makeTagNamingApiNode(['bad-tag']);
    $context = makeTagNamingContext($api);

    $findings = iterator_to_array($rule->checkApi($api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->location->jsonPointer)->toBe('#/tags/0');
});

it('uses the correct index when the second tag is offending', function (): void {
    $rule = new TagNameNamingInconsistent();
    $api = makeTagNamingApiNode(['Projects', 'bad-tag']);
    $context = makeTagNamingContext($api);

    $findings = iterator_to_array($rule->checkApi($api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->location->jsonPointer)->toBe('#/tags/1');
});

it('provides a fix hint with the case label and example', function (): void {
    $rule = new TagNameNamingInconsistent();
    $api = makeTagNamingApiNode(['bad-tag']);
    $context = makeTagNamingContext($api);

    $findings = iterator_to_array($rule->checkApi($api, $context));

    expect($findings[0]->fixHint)
        ->toContain('PascalCase')
        ->toContain(IdentifierCase::Pascal->example());
});

it('kebab case: passes a valid kebab-case tag name', function (): void {
    $rule = new TagNameNamingInconsistent(IdentifierCase::Kebab);
    $api = makeTagNamingApiNode(['import-jobs']);
    $context = makeTagNamingContext($api);

    $findings = iterator_to_array($rule->checkApi($api, $context));

    expect($findings)->toBe([]);
});

it('kebab case: flags a PascalCase tag name', function (): void {
    $rule = new TagNameNamingInconsistent(IdentifierCase::Kebab);
    $api = makeTagNamingApiNode(['ImportJobs']);
    $context = makeTagNamingContext($api);

    $findings = iterator_to_array($rule->checkApi($api, $context));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('kebab-case');
});
